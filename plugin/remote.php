<?php

use dokuwiki\Extension\RemotePlugin;
use dokuwiki\Remote\AccessDeniedException;
use dokuwiki\Remote\ApiCore;
use dokuwiki\Remote\RemoteException;
use dokuwiki\Utf8\PhpString;

/**
 * Remote API for the review queue. A public method here becomes an MCP tool
 * once it is on helper/capability.php's TOOLS allowlist (ADR-0007), and the
 * docblocks below double as the tool descriptions an agent reads - keep them
 * explicit and unambiguous, and never point at a method that is not on that
 * list.
 *
 * These exist because a queued change is deliberately invisible in the
 * normal read path (ADR-0001/ADR-0004): the published text is all the wiki
 * will show you, and searches never match unreviewed drafts. Without these
 * methods an agent would silently keep rewriting the live revision and
 * clobber its own earlier, still-unreviewed work.
 *
 * IMPORTANT for the docblocks below: keep every @param/@return/@throws tag
 * on a SINGLE line. DokuWiki's docblock parser strips only the first line
 * of a tag (inc/Remote/OpenApiDoc/DocBlock.php), so continuation lines leak
 * into the generated tool description and produce garbled MCP tool docs.
 * Describe return structures in the prose part above the tags instead.
 */
class remote_plugin_reviewqueue extends RemotePlugin
{
    /** Cap on how many pages searchWithContext() reports, oldest/most-relevant first. */
    protected const SEARCH_MAX_PAGES = 20;

    /** Cap on how many line hits searchWithContext()/the pending half report per page. */
    protected const SEARCH_MAX_HITS_PER_PAGE = 5;

    /**
     * Get the page text to base an edit on, accounting for your own unreviewed changes.
     *
     * ALWAYS call this before editing a page on a wiki with a review queue - it is the
     * only read that accounts for your own pending work. If you have an unreviewed
     * change pending on this page, this returns that pending text, so your next edit
     * builds on your own latest work instead of silently reverting it. Otherwise it
     * returns the live text.
     *
     * Returns keys: "text" (the wiki text to edit), "source" ("pending" if it came
     * from your unreviewed change, "live" if from the published page), "pendingId"
     * (the change id when source is "pending", otherwise 0), and "warning" (a
     * non-empty string when something needs your attention, e.g. several of your
     * unreviewed changes are stacked on this page).
     *
     * @param string $page page id
     * @return array the text to edit plus its provenance
     * @throws AccessDeniedException no read access for page
     * @throws RemoteException no page id given
     */
    public function getPageToEdit($page)
    {
        $page = $this->checkPageAccess($page);

        $mine = $this->myPendingFor($page);
        if (!$mine) {
            return [
                'text'      => rawWiki($page),
                'source'    => 'live',
                'pendingId' => 0,
                'warning'   => '',
            ];
        }

        $latest = end($mine);
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        $warning = '';
        if (count($mine) > 1) {
            $ids = implode(', #', array_column($mine, 'id'));
            $warning = 'You have ' . count($mine) . ' unreviewed changes stacked on this page (#' .
                $ids . '). Only the newest is returned here. Ask a reviewer to work through ' .
                'them, or expect the older ones to conflict.';
        }

        return [
            'text'      => $store->getContent($latest['id']),
            'source'    => 'pending',
            'pendingId' => $latest['id'],
            'warning'   => $warning,
        ];
    }

    /**
     * List your own changes that are still waiting for review.
     *
     * Use this to check whether earlier edits of yours went live or are still
     * queued. A queued change is NOT visible on the wiki and NOT findable by search.
     *
     * Each entry has keys: "id" (the change id), "target" (page id), "summary",
     * "state" and "created" (unix timestamp).
     *
     * @return array one entry per pending change of yours
     */
    public function listMyPending()
    {
        $records = [];
        foreach ($this->myPending() as $record) {
            $records[] = [
                'id'      => $record['id'],
                'target'  => $record['target'],
                'summary' => $record['summary'],
                'state'   => $record['state'],
                'created' => $record['created'],
            ];
        }
        return $records;
    }

    /**
     * Search the text of your own changes that are still awaiting review.
     *
     * The wiki's normal search (core.searchPages) only ever matches published
     * text, so it cannot find anything you have written but that has not been
     * approved yet. Run this alongside core.searchPages whenever you search in
     * order to decide what to write - otherwise you will not see that you
     * already covered a topic in an unreviewed change, and will write it a
     * second time on another page.
     *
     * Matching is a simple case-insensitive substring search over the proposed
     * text, the page id and the edit summary. Each hit has keys: "id" (change
     * id), "target" (page id), "summary", "created" and "snippet" (surrounding
     * text of the first match).
     *
     * @param string $query the text to look for
     * @return array one entry per matching pending change of yours
     * @throws RemoteException no query given
     */
    public function searchMyPending($query)
    {
        $query = trim((string) $query);
        if ($query === '') throw new RemoteException('No query given', 131);

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        $hits = [];
        foreach ($this->myPending() as $record) {
            // Own drafts, but honour the target's ACL anyway: rights may have
            // been withdrawn between submitting the change and searching for
            // it. Media needs upload rights rather than read rights.
            $need = ($record['type'] ?? 'page') === 'media' ? AUTH_UPLOAD : AUTH_READ;
            if (auth_quickaclcheck($record['target']) < $need) continue;

            $text = $store->getContent($record['id']);
            $haystack = $text . "\n" . $record['target'] . "\n" . $record['summary'];
            $pos = stripos($haystack, $query);
            if ($pos === false) continue;

            $hits[] = [
                'id'      => $record['id'],
                'target'  => $record['target'],
                'summary' => $record['summary'],
                'created' => $record['created'],
                'snippet' => $this->snippet($text, $query),
            ];
        }

        return $hits;
    }

    /**
     * Get the current state of one of your submitted changes, including the
     * reviewer's comment when it was rejected.
     *
     * Returns keys: "id", "target" (page id), "state", "comment" (the reviewer's
     * reason, empty if none was given), "reviewer" and "reviewedAt". The state is
     * one of: "pending" (still waiting), "conflicted" (the page changed underneath
     * it and it needs manual resolution), "approved" (now live), "rejected" (see
     * comment) or "superseded" (replaced by a later change).
     *
     * @param int $id the change id you were given when the change was queued
     * @return array the change's current review state
     * @throws AccessDeniedException the change is not yours
     * @throws RemoteException no such change
     */
    public function getStatus($id)
    {
        $record = $this->checkChangeAccess($id);

        return [
            'id'         => $record['id'],
            'target'     => $record['target'],
            'state'      => $record['state'],
            'comment'    => (string) $record['comment'],
            'reviewer'   => (string) $record['reviewer'],
            'reviewedAt' => (int) $record['reviewedAt'],
        ];
    }

    /**
     * Get the full proposed text of one of your submitted changes.
     *
     * An empty string means the change proposes deleting the page.
     *
     * @param int $id the change id
     * @return string the wiki text exactly as submitted
     * @throws AccessDeniedException the change is not yours
     * @throws RemoteException no such change
     */
    public function getPendingText($id)
    {
        $this->checkChangeAccess($id);
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');
        return $store->getContent($id);
    }

    /**
     * Replace the full proposed text of one of your own still-open changes, instead of creating
     * a new one - the escape hatch for when a rewrite is too large or too scattered for the
     * range write tools (replaceSection/insertSection/deleteSection/replaceLines/replaceText).
     *
     * Those range tools already continue your existing change under the hood (see
     * docs/design/adr-0006), so prefer them for anything section- or line-sized; use this only
     * for a genuine full rewrite. A change already approved, rejected, conflicted or withdrawn
     * cannot be continued this way - submit a new one with the range write tools instead.
     *
     * Returns "status" (always "updated"), "pendingId", "target" (page id), "contentHash" (a
     * short hash of the new text, for a later write tool's $expect), "bytesBefore" and
     * "bytesAfter".
     *
     * @param int $id the change id, from listMyPending or the "submitted for review" message
     * @param string $text the new full proposed text
     * @param string $summary new edit summary; leave empty to keep the original one
     * @return array the update outcome, see above
     * @throws AccessDeniedException the change is not yours
     * @throws RemoteException no such change, the change is no longer open, or a storage failure
     */
    public function updatePendingChange($id, $text, $summary = '')
    {
        $record = $this->checkOwnChangeAccess($id);
        if ($record['state'] !== 'pending') {
            throw new RemoteException(
                "Change #{$record['id']} is {$record['state']}, not pending, and can no longer be updated",
                121
            );
        }
        if (($record['type'] ?? 'page') !== 'page') {
            // A media change's payload lives in queue/<id>.media (putMedia()),
            // not queue/<id>.content - writing $text there would just create
            // an unused file and stamp a meaningless contentHash on the
            // record, never actually touching the pending upload.
            throw new RemoteException(
                "Change #{$record['id']} is a media change; updatePendingChange only works for pages",
                131
            );
        }

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');
        $bytesBefore = strlen($store->getContent($record['id']));

        $summary = trim((string) $summary);
        $metaPatch = $summary !== '' ? ['summary' => $summary] : [];
        $text = (string) $text;

        try {
            $store->updateContent($record['id'], $text, $metaPatch);
        } catch (\RuntimeException $e) {
            throw new RemoteException(
                'The review queue could not be written to, so your update was not saved.',
                500,
                $e
            );
        }

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');

        return [
            'status'      => 'updated',
            'pendingId'   => $record['id'],
            'target'      => $record['target'],
            'contentHash' => $range->hash($text),
            'bytesBefore' => $bytesBefore,
            'bytesAfter'  => strlen($text),
        ];
    }

    /**
     * Withdraw one of your own still-open changes - use this when you decide it should not be
     * reviewed after all, instead of leaving it for a reviewer to reject.
     *
     * Withdrawing is your own decision and does not require a reviewer; it moves the change to
     * the same archive a decided change ends up in, so it stays visible there, just no longer
     * pending. A change already approved, rejected, conflicted or withdrawn cannot be withdrawn
     * again.
     *
     * @param int $id the change id, from listMyPending or the "submitted for review" message
     * @param string $reason optional note for the record; retrievable later via getStatus
     * @return array "id", "target" (page id), "state" (always "withdrawn")
     * @throws AccessDeniedException the change is not yours
     * @throws RemoteException no such change, the change is no longer open, or a storage failure
     */
    public function withdrawPendingChange($id, $reason = '')
    {
        $record = $this->checkOwnChangeAccess($id);
        if ($record['state'] !== 'pending') {
            throw new RemoteException(
                "Change #{$record['id']} is {$record['state']}, not pending, and can no longer be withdrawn",
                121
            );
        }

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');
        try {
            $store->withdraw($record['id'], (string) $reason);
        } catch (\RuntimeException $e) {
            throw new RemoteException(
                'The review queue could not be written to, so your change was not withdrawn.',
                500,
                $e
            );
        }

        return ['id' => $record['id'], 'target' => $record['target'], 'state' => 'withdrawn'];
    }

    /**
     * The page's table of contents, so you can read or edit one section instead of the whole page.
     *
     * A whole page can easily be too large to read or edit in one call - use this first, then
     * getSection/getLines/findInPage to work with just the part you need. Every heading becomes
     * one entry, in document order, regardless of the wiki's configured maximum section-edit
     * level - deeper headings simply get their own entry too, unlike the section-edit buttons a
     * human sees in the browser. Entry 0 is always the part of the page before its first heading
     * (empty if the page starts with a heading).
     *
     * $source selects which text to read: "auto" (default) is your own unreviewed change for
     * this page if one exists, otherwise the live page; "live" is always the published text;
     * "pending" is your own unreviewed change and fails if you have none. See getPageToEdit for
     * why this distinction matters.
     *
     * Returns "source" ("live" or "pending", whichever was actually used), "pendingId" (0 if
     * source is "live"), "contentHash" (a short hash of the whole text - pass it back to a write
     * tool's $expect to detect that the page changed underneath you), "bytes", "lines", and
     * "sections": one entry per heading with "index" (use this to address the section in
     * getSection/replaceSection/etc.), "level", "title", "hid" (its anchor), "range" (byte range
     * in the page's own section-edit format), "lineStart", "lineEnd", "bytes", "lines", "hash"
     * (short hash of that section's own text alone, matching getSection with children=false),
     * and "hashWithChildren" (short hash including any nested subsections - use THIS as $expect
     * for replaceSection/deleteSection, which always act on a section together with its
     * children; "hash" would never match theirs for a heading that has any).
     *
     * @param string $page page id
     * @param string $source one of: auto, live, pending
     * @return array table of contents plus provenance, see above
     * @throws AccessDeniedException no read access for page
     * @throws RemoteException no page id given, page does not exist, or (source=pending) no pending change of yours exists
     */
    public function getPageOutline($page, $source = 'auto')
    {
        $page = $this->checkPageAccess($page);
        $resolved = $this->effectiveText($page, $source);

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');
        $sections = $range->outline($resolved['text']);
        foreach ($sections as &$section) {
            unset($section['byteStart'], $section['byteEnd']);
        }
        unset($section);

        return [
            'source'      => $resolved['source'],
            'pendingId'   => $resolved['pendingId'],
            'contentHash' => $range->hash($resolved['text']),
            'bytes'       => strlen($resolved['text']),
            'lines'       => $range->countLines($resolved['text']),
            'sections'    => $sections,
        ];
    }

    /**
     * Read the text of one section of a page, optionally including its nested subsections.
     *
     * Call getPageOutline first to find the section you want. $section accepts, tried in this
     * order, the numeric "index" from getPageOutline, the "range" string from getPageOutline, an
     * anchor as "#hid", or the exact heading title (case-insensitive; if more than one heading
     * shares that title, this fails and names them so you can use the index instead).
     *
     * Returns "text" (the section's wiki text, including its own heading line), "range" (pass
     * this to replaceSection/deleteSection), "hash" (a short hash of exactly this text - pass it
     * back as $expect to a write tool to detect that this section changed underneath you), plus
     * "source" and "pendingId" as in getPageOutline.
     *
     * @param string $page page id
     * @param string $section section index, range, #hid, or heading title, from getPageOutline
     * @param bool $children include nested subsections (default true); false stops at the next heading of any level
     * @param string $source one of: auto, live, pending
     * @return array the section's text plus provenance, see above
     * @throws AccessDeniedException no read access for page
     * @throws RemoteException no page id given, page does not exist, no pending change, no matching section, or ambiguous title
     */
    public function getSection($page, $section, $children = true, $source = 'auto')
    {
        $page = $this->checkPageAccess($page);
        $resolved = $this->effectiveText($page, $source);

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');
        $match = $this->resolveOrThrow($range, $resolved['text'], $section, (bool) $children);

        return [
            'source'    => $resolved['source'],
            'pendingId' => $resolved['pendingId'],
            'range'     => $match['range'],
            'hash'      => $match['hash'],
            'text'      => substr($resolved['text'], $match['byteStart'], $match['byteEnd'] - $match['byteStart']),
        ];
    }

    /**
     * Read a range of lines from a page - use this for pages without useful headings, where
     * getSection does not apply.
     *
     * Returns "text" (the requested lines, joined with newlines), "lineStart"/"lineEnd" (the
     * actual 1-based range returned, clamped to the page's length), "hash" (a short hash of
     * exactly this text - pass it back as $expect to replaceLines), plus "source" and "pendingId"
     * as in getPageOutline.
     *
     * @param string $page page id
     * @param int $from first line to read, 1-based
     * @param int $count number of lines to read; 0 means to the end of the page
     * @param string $source one of: auto, live, pending
     * @return array the requested lines plus provenance, see above
     * @throws AccessDeniedException no read access for page
     * @throws RemoteException no page id given, page does not exist, no pending change, or line range out of bounds
     */
    public function getLines($page, $from, $count = 0, $source = 'auto')
    {
        $page = $this->checkPageAccess($page);
        $resolved = $this->effectiveText($page, $source);

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');
        try {
            [$byteStart, $byteEnd] = $range->lines($resolved['text'], (int) $from, (int) $count);
        } catch (\InvalidArgumentException $e) {
            throw new RemoteException($e->getMessage(), 131);
        }

        $text = substr($resolved['text'], $byteStart, $byteEnd - $byteStart);

        return [
            'source'    => $resolved['source'],
            'pendingId' => $resolved['pendingId'],
            'lineStart' => $range->lineAt($resolved['text'], $byteStart),
            'lineEnd'   => $range->lineAt($resolved['text'], max($byteStart, $byteEnd - 1)),
            'hash'      => $range->hash($text),
            'text'      => $text,
        ];
    }

    /**
     * Find a piece of text within one page, with line numbers and surrounding context - so you
     * can locate something without reading the whole page first.
     *
     * Matching is a case-insensitive substring search, one entry per matching line.
     *
     * Returns one entry per matching line: "line" (1-based), "section" (the getPageOutline index
     * of the section it falls in), "text" (that line), "context" (that line plus $context lines
     * before and after, joined with newlines), plus "source" and "pendingId" as in
     * getPageOutline are included once at the top level.
     *
     * @param string $page page id
     * @param string $query text to look for
     * @param int $context lines of context on either side of each match
     * @param string $source one of: auto, live, pending
     * @return array "source", "pendingId", and "hits" (one entry per matching line, see above)
     * @throws AccessDeniedException no read access for page
     * @throws RemoteException no page id given, no query given, page does not exist, or no pending change
     */
    public function findInPage($page, $query, $context = 2, $source = 'auto')
    {
        $page = $this->checkPageAccess($page);
        $query = trim((string) $query);
        if ($query === '') throw new RemoteException('No query given', 131);

        $resolved = $this->effectiveText($page, $source);

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');

        return [
            'source'    => $resolved['source'],
            'pendingId' => $resolved['pendingId'],
            'hits'      => $range->findInText($resolved['text'], $query, max(0, (int) $context)),
        ];
    }

    /**
     * Search the whole wiki for a piece of text, with line numbers and context - so you can
     * decide what to read next instead of pulling matching pages in full.
     *
     * Unlike core.searchPages, this returns line-level context instead of a single snippet, and
     * $scope lets it cover your own unreviewed drafts too - which core.searchPages never does
     * (see searchMyPending). Use this as your default search on a wiki with a review queue.
     *
     * $scope selects what to search: "live" only published pages (matches core.searchPages'
     * access to your ACLs), "pending" only your own open drafts, "all" (default) both - a hit
     * from your own draft is marked accordingly, see below.
     *
     * Returns one entry per matching page, each with "page" (page id), "source" ("live" or
     * "pending"), "pendingId" (0 for a live hit), and "hits" (one entry per matching line: "line",
     * "section" - the getPageOutline index it falls in - "text", and "context", same shape as
     * findInPage). Capped to the 20 most relevant pages and 5 hits per page, to keep results
     * usable regardless of wiki size.
     *
     * @param string $query text to look for
     * @param int $context lines of context on either side of each match
     * @param string $scope one of: live, pending, all
     * @return array one entry per matching page, see above
     * @throws RemoteException no query given, or an unknown scope
     */
    public function searchWithContext($query, $context = 2, $scope = 'all')
    {
        $query = trim((string) $query);
        if ($query === '') throw new RemoteException('No query given', 131);
        if (!in_array($scope, ['live', 'pending', 'all'], true)) {
            throw new RemoteException("Unknown scope '$scope', expected live, pending or all", 131);
        }

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');
        $context = max(0, (int) $context);
        $results = [];

        if ($scope !== 'pending') {
            $regex = [];
            $pageHits = ft_pageSearch($query, $regex);
            $count = 0;
            foreach (array_keys($pageHits) as $id) {
                if (++$count > self::SEARCH_MAX_PAGES) break;
                $hits = array_slice($range->findInText(rawWiki($id), $query, $context), 0, self::SEARCH_MAX_HITS_PER_PAGE);
                if (!$hits) continue;
                $results[] = ['page' => $id, 'source' => 'live', 'pendingId' => 0, 'hits' => $hits];
            }
        }

        if ($scope !== 'live') {
            /** @var helper_plugin_reviewqueue_store $store */
            $store = $this->loadHelper('reviewqueue_store');
            $count = 0;
            foreach ($this->myPending() as $record) {
                $need = ($record['type'] ?? 'page') === 'media' ? AUTH_UPLOAD : AUTH_READ;
                if (auth_quickaclcheck($record['target']) < $need) continue;

                $text = $store->getContent($record['id']);
                $hits = array_slice($range->findInText($text, $query, $context), 0, self::SEARCH_MAX_HITS_PER_PAGE);
                if (!$hits) continue;

                // Cap the number of *matches*, not the number of drafts looked
                // at. myPending() is oldest-first, so counting every record
                // examined meant an author with more than SEARCH_MAX_PAGES open
                // drafts stopped finding their newest ones - which is exactly
                // the work searchMyPending/searchWithContext exist to surface
                // (ADR-0004). searchMyPending scans them all and had no such
                // cap, so the two disagreed about what the author owns.
                if (++$count > self::SEARCH_MAX_PAGES) break;
                $results[] = ['page' => $record['target'], 'source' => 'pending', 'pendingId' => $record['id'], 'hits' => $hits];
            }
        }

        return $results;
    }

    /**
     * Create a page that does not exist yet.
     *
     * This is the only way to bring a new page into being; every other write tool addresses a
     * range of something already there. Like all of them, the result goes into the review queue
     * rather than live, unless you are not subject to review.
     *
     * Refused if the page already exists, or if you already have an open draft creating it -
     * read it with getPageToEdit and continue it with the range write tools instead, so you
     * don't stack a second unreviewed change on top of your own (see docs/design/adr-0004).
     *
     * Returns "status" ("live" if you are not subject to review and this published immediately,
     * "queued" if this created a pending change), "pendingId" (0 for "live"), and "target".
     *
     * @param string $page page id for the new page
     * @param string $text the full text of the new page
     * @param string $summary edit summary
     * @return array the write outcome, see above
     * @throws AccessDeniedException no create access for page
     * @throws RemoteException page already exists, a draft for it is already open, or empty text
     */
    public function createPage($page, $text, $summary = '')
    {
        $page = cleanID($page);
        if ($page === '') throw new RemoteException('No page id given', 131);

        // Caught here rather than in writeEffectiveText(), whose empty-text
        // message points at deletePage - which would then answer "does not
        // exist, so there is nothing to delete" and leave the caller going in
        // circles.
        if (trim((string) $text) === '') {
            throw new RemoteException("A new page needs text - '$page' would be created empty", 131);
        }

        // Creating needs more than the read access checkPageAccess() asks for.
        if (auth_quickaclcheck($page) < AUTH_CREATE) {
            throw new AccessDeniedException('You are not allowed to create this page', 111);
        }

        if (page_exists($page)) {
            throw new RemoteException(
                "Page '$page' already exists - read it with getPageToEdit and change it with " .
                'replaceSection, replaceLines or replaceText instead',
                121
            );
        }

        $mine = $this->myPendingFor($page);
        if ($mine) {
            $open = end($mine);
            throw new RemoteException(
                "You already have unreviewed change #{$open['id']} creating '$page' - continue it " .
                'with the range write tools or updatePendingChange instead of stacking another one',
                121
            );
        }

        return $this->writeEffectiveText($page, (string) $text, 0, $summary);
    }

    /**
     * Propose deleting a page.
     *
     * Deleting is a reviewable intent like any other, so this goes into the queue rather than
     * removing anything: approving the change is what actually deletes the page. Every other
     * write tool refuses to empty a page, precisely so that a deletion is always something you
     * asked for on purpose rather than the accidental result of replacing a range with nothing.
     *
     * Refused if the page does not exist, or if you already have an open draft for it - decide
     * what that draft should be first (withdrawPendingChange if you no longer want it).
     *
     * Returns "status" ("live" if you are not subject to review and this deleted immediately,
     * "queued" if this created a pending change), "pendingId" (0 for "live"), and "target".
     *
     * @param string $page page id to delete
     * @param string $summary edit summary, ideally saying why
     * @return array the write outcome, see above
     * @throws AccessDeniedException no delete access for page
     * @throws RemoteException page does not exist, or a draft for it is already open
     */
    public function deletePage($page, $summary = '')
    {
        $page = cleanID($page);
        if ($page === '') throw new RemoteException('No page id given', 131);

        if (auth_quickaclcheck($page) < AUTH_DELETE) {
            throw new AccessDeniedException('You are not allowed to delete this page', 111);
        }

        if (!page_exists($page)) {
            throw new RemoteException("Page '$page' does not exist, so there is nothing to delete", 121);
        }

        $mine = $this->myPendingFor($page);
        if ($mine) {
            $open = end($mine);
            throw new RemoteException(
                "You already have unreviewed change #{$open['id']} for '$page' - withdraw it with " .
                'withdrawPendingChange before proposing a deletion, so the reviewer is not left ' .
                'with two conflicting proposals for the same page',
                121
            );
        }

        // An empty text is how DokuWiki itself expresses a deletion, and how
        // the queue has always recorded one (see docs/design/spec.md) - the
        // difference is only that asking for it is explicit here.
        return $this->writeEffectiveText($page, '', 0, $summary, true);
    }

    /**
     * Replace one section of a page, without sending the rest of the page over the wire.
     *
     * $section is resolved the same way as in getSection, against the same effective text
     * (your own open draft if you have one, otherwise the live page) - including its nested
     * subsections, so replacing a heading replaces everything under it too. If you only want
     * a change to the heading's own text, read it with $children=false in getSection first
     * and use replaceLines or replaceText instead.
     *
     * If you have an open draft for this page, this continues it in place rather than creating
     * a new one (see docs/design/adr-0006). Pass $expect (the section's "hash" from
     * getPageOutline/getSection) to
     * refuse the write if the section changed since you read it.
     *
     * Returns "status" ("live" if you are not subject to review and this published
     * immediately, "queued" if this created a new pending change, "updated" if it continued
     * your existing one), "pendingId" (0 for "live"), and "target" (page id).
     *
     * @param string $page page id
     * @param string $section section index, range, #hid, or heading title, from getPageOutline
     * @param string $text the new text for this section, replacing it entirely (include the heading line to keep it)
     * @param string $expect the section's "hash" from getSection, or "hashWithChildren" from getPageOutline; leave empty to skip the check
     * @param string $summary edit summary
     * @return array the write outcome, see above
     * @throws AccessDeniedException no write access for page
     * @throws RemoteException page/section not found, ambiguous section, stale $expect, or this would empty the page
     */
    public function replaceSection($page, $section, $text, $expect = '', $summary = '')
    {
        $page = $this->checkPageAccess($page);
        $resolved = $this->effectiveText($page, 'auto');

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');
        $match = $this->resolveOrThrow($range, $resolved['text'], $section, true);
        $this->checkExpect($match['hash'], $expect, 'section');

        $newText = $range->spliceBytes($resolved['text'], $match['byteStart'], $match['byteEnd'], (string) $text);
        return $this->writeEffectiveText($page, $newText, $resolved['pendingId'], $summary);
    }

    /**
     * Insert a new section next to an existing one, without sending the rest of the page.
     *
     * $anchor is resolved the same way as $section in getSection (index, range, #hid, or
     * heading title; empty string means the very top of the page). $position places the new
     * text relative to it: "before" right before the anchor's own heading (as a new sibling
     * preceding it); "after" right after the anchor and everything nested under it (as a new
     * sibling following the whole subtree); "start" right after the anchor's own heading line,
     * before its existing body; "end" at the end of the anchor's own body, before any of its
     * nested subsections.
     *
     * Continues your existing open draft for this page in place, same as replaceSection - see
     * there for details.
     *
     * Returns the same "status"/"pendingId"/"target" as replaceSection.
     *
     * @param string $page page id
     * @param string $anchor section index, range, #hid, or heading title, from getPageOutline; empty means the top of the page
     * @param string $position one of: before, after, start, end
     * @param string $text the wiki text to insert, e.g. a full "====== Heading ======\n..." section
     * @param string $summary edit summary
     * @return array the write outcome, see replaceSection
     * @throws AccessDeniedException no write access for page
     * @throws RemoteException page/anchor not found, ambiguous anchor, unknown position, or this would empty the page
     */
    public function insertSection($page, $anchor, $position, $text, $summary = '')
    {
        $page = $this->checkPageAccess($page);
        $resolved = $this->effectiveText($page, 'auto');

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');
        $at = $this->insertionPoint($range, $resolved['text'], (string) $anchor, $position);

        $newText = $range->spliceBytes($resolved['text'], $at, $at, (string) $text);
        return $this->writeEffectiveText($page, $newText, $resolved['pendingId'], $summary);
    }

    /**
     * Remove one section of a page, including its nested subsections, without sending the
     * rest of the page over the wire.
     *
     * $section is resolved the same way as in getSection. Continues your existing open draft
     * for this page in place, same as replaceSection - see there for details. Refused if it
     * would leave the page empty (that is a deletion of the whole page, which goes through
     * deletePage instead).
     *
     * Returns the same "status"/"pendingId"/"target" as replaceSection.
     *
     * @param string $page page id
     * @param string $section section index, range, #hid, or heading title, from getPageOutline
     * @param string $expect the section's "hash" from getSection, or "hashWithChildren" from getPageOutline; leave empty to skip the check
     * @param string $summary edit summary
     * @return array the write outcome, see replaceSection
     * @throws AccessDeniedException no write access for page
     * @throws RemoteException page/section not found, ambiguous section, stale $expect, or this would empty the page
     */
    public function deleteSection($page, $section, $expect = '', $summary = '')
    {
        $page = $this->checkPageAccess($page);
        $resolved = $this->effectiveText($page, 'auto');

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');
        $match = $this->resolveOrThrow($range, $resolved['text'], $section, true);
        $this->checkExpect($match['hash'], $expect, 'section');

        $newText = $range->spliceBytes($resolved['text'], $match['byteStart'], $match['byteEnd'], '');
        return $this->writeEffectiveText($page, $newText, $resolved['pendingId'], $summary);
    }

    /**
     * Replace a range of lines in a page - use this for pages without useful headings, where
     * replaceSection does not apply.
     *
     * Unlike the other write tools, $expect is required here rather than optional: a line
     * number can silently point at the wrong place once the page has changed, where a section
     * or search-text match would simply fail to resolve instead. Read the range with getLines
     * first and pass back its "hash".
     *
     * Continues your existing open draft for this page in place, same as replaceSection - see
     * there for details.
     *
     * Returns the same "status"/"pendingId"/"target" as replaceSection.
     *
     * @param string $page page id
     * @param int $from first line to replace, 1-based
     * @param int $to last line to replace, 1-based
     * @param string $text the new text for this line range
     * @param string $expect the range's "hash" from getLines - required, not optional
     * @param string $summary edit summary
     * @return array the write outcome, see replaceSection
     * @throws AccessDeniedException no write access for page
     * @throws RemoteException page not found, line range out of bounds, missing or stale $expect, or this would empty the page
     */
    public function replaceLines($page, $from, $to, $text, $expect, $summary = '')
    {
        $page = $this->checkPageAccess($page);
        $resolved = $this->effectiveText($page, 'auto');

        $expect = trim((string) $expect);
        if ($expect === '') {
            throw new RemoteException(
                'replaceLines requires $expect - read the range with getLines first and pass back its "hash", ' .
                'so a line number that has silently drifted to a different place is caught instead of overwritten',
                131
            );
        }

        /** @var helper_plugin_reviewqueue_range $range */
        $range = $this->loadHelper('reviewqueue_range');
        try {
            [$byteStart, $byteEnd] = $range->resolveLines($resolved['text'], (int) $from, (int) $to);
        } catch (\InvalidArgumentException $e) {
            throw new RemoteException($e->getMessage(), 131);
        }

        $actualHash = $range->hash(substr($resolved['text'], $byteStart, $byteEnd - $byteStart));
        $this->checkExpect($actualHash, $expect, 'line range');

        $newText = $range->spliceBytes($resolved['text'], $byteStart, $byteEnd, (string) $text);
        return $this->writeEffectiveText($page, $newText, $resolved['pendingId'], $summary);
    }

    /**
     * Replace an exact piece of text in a page - the cheapest and safest write when you know
     * the exact wording to change, without addressing it by section or line number at all.
     *
     * $search must occur in the page exactly as given (including whitespace). If it does not
     * occur at all, or occurs more than once and $all is not set, this is refused rather than
     * guessing - re-read the page (or narrow $search to something unique) instead of risking
     * the wrong occurrence.
     *
     * Continues your existing open draft for this page in place, same as replaceSection - see
     * there for details.
     *
     * Returns the same "status"/"pendingId"/"target" as replaceSection.
     *
     * @param string $page page id
     * @param string $search exact text to find, whitespace included
     * @param string $replace text to put in its place
     * @param bool $all replace every occurrence instead of requiring exactly one
     * @param string $summary edit summary
     * @return array the write outcome, see replaceSection
     * @throws AccessDeniedException no write access for page
     * @throws RemoteException page not found, no/ambiguous match for $search, or this would empty the page
     */
    public function replaceText($page, $search, $replace, $all = false, $summary = '')
    {
        $page = $this->checkPageAccess($page);
        $resolved = $this->effectiveText($page, 'auto');
        $text = $resolved['text'];

        $search = (string) $search;
        if ($search === '') throw new RemoteException('No search text given', 131);

        $count = substr_count($text, $search);
        if ($count === 0) {
            throw new RemoteException(
                "'$search' does not occur in '$page' - re-read it, the text may have changed since",
                131
            );
        }
        if ($count > 1 && !$all) {
            throw new RemoteException(
                "'$search' occurs $count times in '$page' - pass \$all=true to replace every occurrence, " .
                'or narrow $search to a piece of text that occurs only once',
                131
            );
        }

        $newText = $all
            ? str_replace($search, (string) $replace, $text)
            : $this->replaceOnce($text, $search, (string) $replace);
        return $this->writeEffectiveText($page, $newText, $resolved['pendingId'], $summary);
    }

    /**
     * Context around the first occurrence of $query in $text, so a caller can
     * judge relevance without fetching the whole draft.
     *
     * @param string $text
     * @param string $query
     * @param int $context characters to include on either side
     * @return string
     */
    protected function snippet($text, $query, $context = 80)
    {
        $pos = stripos($text, $query);
        if ($pos === false) return PhpString::substr($text, 0, $context * 2);

        $start = max(0, $pos - $context);
        $length = PhpString::strlen($query) + ($context * 2);
        $snippet = PhpString::substr($text, $start, $length);

        if ($start > 0) $snippet = '…' . $snippet;
        if ($start + $length < PhpString::strlen($text)) $snippet .= '…';

        return $snippet;
    }

    /**
     * @param string $page
     * @return string cleaned page id
     * @throws AccessDeniedException
     * @throws RemoteException
     */
    protected function checkPageAccess($page)
    {
        $page = cleanID($page);
        if ($page === '') throw new RemoteException('No page id given', 131);
        if (auth_quickaclcheck($page) < AUTH_READ) {
            throw new AccessDeniedException('You are not allowed to read this page', 111);
        }
        return $page;
    }

    /**
     * A change may only be inspected by its own author, or by a reviewer.
     *
     * @param int $id
     * @return array the record
     * @throws AccessDeniedException
     * @throws RemoteException
     */
    protected function checkChangeAccess($id)
    {
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');
        $record = $store->get((int) $id);
        if (!$record) throw new RemoteException('No such change', 121);

        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');

        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');

        // Your own change, or one you are entitled to review - which requires
        // read access to the target, not merely reviewer status, so the queue
        // cannot be used to read around the wiki's ACLs.
        $mine = $record['author'] === $user;
        if (!$mine && !$policy->mayReviewTarget($user, $record['target'], $record['type'])) {
            throw new AccessDeniedException('This change is not yours', 111);
        }
        return $record;
    }

    /**
     * Stricter than checkChangeAccess(): for the tools that let the author modify or withdraw
     * their own change, a reviewer's read access to the target must NOT be enough - deciding
     * what an author's own draft says is the author's call, not a reviewer's.
     *
     * @param int $id
     * @return array the record
     * @throws AccessDeniedException
     * @throws RemoteException
     */
    protected function checkOwnChangeAccess($id)
    {
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');
        $record = $store->get((int) $id);
        if (!$record) throw new RemoteException('No such change', 121);

        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');
        if ($record['author'] !== $user) {
            throw new AccessDeniedException('This change is not yours', 111);
        }
        return $record;
    }

    /**
     * @return array[] the current user's pending changes, oldest first
     */
    protected function myPending()
    {
        global $INPUT;
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');
        return $store->listChanges([
            'author' => $INPUT->server->str('REMOTE_USER'),
            'state'  => 'pending',
        ]);
    }

    /**
     * @param string $page
     * @return array[] the current user's pending changes for one page, oldest first
     */
    protected function myPendingFor($page)
    {
        global $INPUT;
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');
        return $store->listChanges([
            'author' => $INPUT->server->str('REMOTE_USER'),
            'state'  => 'pending',
            'type'   => 'page',
            'target' => $page,
        ]);
    }

    /**
     * Resolve which text a range-read tool should work on, per the $source parameter shared by
     * getPageOutline/getSection/getLines/findInPage. Same "your own draft, or else the live
     * text" precedence as getPageToEdit(), just parameterised so a caller can pin one side
     * explicitly instead of always taking the newest.
     *
     * @param string $page cleaned page id, as returned by checkPageAccess()
     * @param string $source one of: auto, live, pending
     * @return array "text", "source" ("live" or "pending"), "pendingId" (0 for "live")
     * @throws RemoteException unknown source, page does not exist, or (source=pending) no pending change of yours
     */
    protected function effectiveText($page, $source)
    {
        $source = $source !== '' ? $source : 'auto';
        if (!in_array($source, ['auto', 'live', 'pending'], true)) {
            throw new RemoteException("Unknown source '$source', expected auto, live or pending", 131);
        }

        $latest = null;
        if ($source !== 'live') {
            $mine = $this->myPendingFor($page);
            $latest = $mine ? end($mine) : null;
        }

        if ($source === 'pending' || ($source === 'auto' && $latest)) {
            if (!$latest) {
                throw new RemoteException("You have no pending change for '$page'", 121);
            }
            /** @var helper_plugin_reviewqueue_store $store */
            $store = $this->loadHelper('reviewqueue_store');
            return ['text' => $store->getContent($latest['id']), 'source' => 'pending', 'pendingId' => $latest['id']];
        }

        if (!page_exists($page)) {
            throw new RemoteException("Page '$page' does not exist - use createPage to create it", 121);
        }
        return ['text' => rawWiki($page), 'source' => 'live', 'pendingId' => 0];
    }

    /**
     * @param helper_plugin_reviewqueue_range $range
     * @param string $text
     * @param string $section section index, range, #hid, or heading title
     * @param bool $children include nested subsections
     * @return array the matching outline entry, see helper_plugin_reviewqueue_range::resolveSection()
     * @throws RemoteException no matching section, or ambiguous title
     */
    protected function resolveOrThrow($range, $text, $section, $children)
    {
        try {
            return $range->resolveSection($text, (string) $section, $children);
        } catch (\InvalidArgumentException $e) {
            throw new RemoteException($e->getMessage(), 131);
        }
    }

    /**
     * Byte offset for insertSection()'s $position, resolved against $anchor - see that
     * method's docblock for what each position means.
     *
     * @param helper_plugin_reviewqueue_range $range
     * @param string $text
     * @param string $anchor section index, range, #hid, or heading title; empty means the top of the page
     * @param string $position one of: before, after, start, end
     * @return int byte offset to insert at
     * @throws RemoteException unknown position, no matching anchor, or ambiguous anchor
     */
    protected function insertionPoint($range, $text, $anchor, $position)
    {
        $position = (string) $position;
        if (!in_array($position, ['before', 'after', 'start', 'end'], true)) {
            throw new RemoteException("Unknown position '$position', expected before, after, start or end", 131);
        }

        if ($position === 'after') {
            return $this->resolveOrThrow($range, $text, $anchor, true)['byteEnd'];
        }

        $match = $this->resolveOrThrow($range, $text, $anchor, false);
        if ($position === 'before') return $match['byteStart'];
        if ($position === 'end') return $match['byteEnd'];

        // 'start': right after the anchor's own heading line, or its very
        // start when it has none (the preamble, index 0, has no heading
        // line to skip past).
        if ($match['level'] === 0) return $match['byteStart'];
        $lineEnd = strpos($text, "\n", $match['byteStart']);
        return $lineEnd === false ? $match['byteEnd'] : $lineEnd + 1;
    }

    /**
     * The staleness guard shared by every range write tool: refuse the write if the caller's
     * $expect does not match what is actually there right now. An empty $expect skips the
     * check - it is optional everywhere except replaceLines, which enforces it itself.
     *
     * @param string $actualHash the current "hash" of the region about to be written
     * @param string $expect the caller-supplied hash to check against, or empty to skip
     * @param string $label named in the error message, e.g. "section" or "line range"
     * @throws RemoteException $expect is set and does not match
     */
    protected function checkExpect($actualHash, $expect, $label)
    {
        $expect = trim((string) $expect);
        if ($expect === '') return;
        if ($expect !== $actualHash) {
            throw new RemoteException(
                "The $label changed since you last read it (expected hash $expect, now $actualHash) - " .
                're-read it before writing',
                131
            );
        }
    }

    /**
     * A range write is for changing part of a page, not for deleting all of it - that is
     * deletePage's job, so a deletion is always something the caller asked for rather than
     * something that fell out of a splice.
     *
     * @param string $newText the full page text a range write is about to produce
     * @param string $page for the error message
     * @throws RemoteException $newText is empty (or whitespace-only)
     */
    protected function refuseEmptyPage($newText, $page)
    {
        if (trim((string) $newText) !== '') return;
        throw new RemoteException(
            "That would leave '$page' empty - if you mean to delete the page, use deletePage instead",
            131
        );
    }

    /**
     * @param string $haystack
     * @param string $search known to occur in $haystack exactly once
     * @param string $replace
     * @return string
     */
    protected function replaceOnce($haystack, $search, $replace)
    {
        $pos = strpos($haystack, $search);
        return substr($haystack, 0, $pos) . $replace . substr($haystack, $pos + strlen($search));
    }

    /**
     * The write funnel every range write tool goes through: never bypass ApiCore::savePage()
     * itself, so ACL, the page lock, spam checks, the queue interception and the search index
     * update all happen exactly as they would for core.savePage - only the returned outcome is
     * richer than a bare boolean.
     *
     * $updateId, when nonzero, tells handleWikipageSave() (action/save.php) to continue that
     * open change in place instead of queuing a new one (docs/design/adr-0006) - callers pass
     * effectiveText()'s "pendingId" here, which is 0 when there is nothing to continue.
     *
     * @param string $page cleaned page id
     * @param string $newText the full new page text
     * @param int $updateId the author's open pending change id to continue, or 0 for none
     * @param string $summary edit summary
     * @param bool $allowEmpty true only for deletePage, the one caller that means an empty text
     * @return array "status" (live, queued, or updated), "pendingId", "target"
     * @throws AccessDeniedException no write access for page, or the page is locked
     * @throws RemoteException the page is locked, blocked, would be an empty new page, or the queue failed to write
     */
    protected function writeEffectiveText($page, $newText, $updateId, $summary, $allowEmpty = false)
    {
        if (!$allowEmpty) $this->refuseEmptyPage($newText, $page);

        action_plugin_reviewqueue_save::$rangeIntent = ['target' => $page, 'updateId' => (int) $updateId];
        action_plugin_reviewqueue_save::$rangeResult = null;

        try {
            (new ApiCore())->savePage($page, $newText, (string) $summary, false);
        } finally {
            action_plugin_reviewqueue_save::$rangeIntent = null;
        }

        $result = action_plugin_reviewqueue_save::$rangeResult;
        action_plugin_reviewqueue_save::$rangeResult = null;

        if ($result === null) {
            // Not intercepted at all: this caller isn't subject to review
            // (or the save happened to be a no-op), so the page is live now.
            return ['status' => 'live', 'pendingId' => 0, 'target' => $page];
        }
        return $result;
    }
}
