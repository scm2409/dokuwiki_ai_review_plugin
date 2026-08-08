<?php

use dokuwiki\Extension\RemotePlugin;
use dokuwiki\Remote\AccessDeniedException;
use dokuwiki\Remote\RemoteException;

/**
 * Remote API for the review queue. Every public method here is exported
 * automatically as an MCP tool by the mcp plugin (see
 * docs/research/kaos-hooks.md), so the docblocks below double as the tool
 * descriptions an agent reads - keep them explicit and unambiguous.
 *
 * These exist because a queued change is deliberately invisible in the
 * normal read path (ADR-0001/ADR-0004): core.getPage returns the live
 * text, and searches never match unreviewed drafts. Without these methods
 * an agent would silently keep rewriting the live revision and clobber
 * its own earlier, still-unreviewed work.
 *
 * IMPORTANT for the docblocks below: keep every @param/@return/@throws tag
 * on a SINGLE line. DokuWiki's docblock parser strips only the first line
 * of a tag (inc/Remote/OpenApiDoc/DocBlock.php), so continuation lines leak
 * into the generated tool description and produce garbled MCP tool docs.
 * Describe return structures in the prose part above the tags instead.
 */
class remote_plugin_reviewqueue extends RemotePlugin
{
    /**
     * Get the page text to base an edit on, accounting for your own unreviewed changes.
     *
     * ALWAYS use this instead of core.getPage before editing a page on a wiki
     * with a review queue. If you have an unreviewed change pending on this page,
     * this returns that pending text - so your next edit builds on your own latest
     * work instead of silently reverting it. Otherwise it returns the live text.
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
        if ($record['author'] !== $user && !$policy->isReviewer($user)) {
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
}
