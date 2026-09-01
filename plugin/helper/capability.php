<?php

use dokuwiki\Extension\Plugin;

/**
 * What a review-scoped account is allowed to do, on every path at once.
 *
 * Kaos has no per-method interception point for the remote API (no RPC_CALL
 * event, Api::call() invokes the method directly) and its own access check is
 * per-user, not per-method - Api::ensureAccessIsAllowed() only asks whether
 * this user may use the remote API at all. So confinement cannot be expressed
 * as "which method"; it has to be expressed as "which door", and there are
 * three of them: the entry script, the do= action, and our own MCP endpoint's
 * tool dispatch.
 *
 * All three ask this class. The rules are a property of the account, not of a
 * transport, so they are defined exactly once here - the same reason
 * helper/policy.php is the only place that answers "does this need review?".
 *
 * Every list is an allowlist. A DokuWiki release that adds an entry script, or
 * a plugin that registers a new remote method, is refused rather than silently
 * admitted - the same fail-closed direction as CLAUDE.md's guiding principle
 * and as the act allowlist this class inherited from action/save.php.
 *
 * See docs/design/adr-0007-agent-confinement.md.
 */
class helper_plugin_reviewqueue_capability extends Plugin
{
    /**
     * Entry scripts a review-scoped account may reach, relative to DOKU_INC.
     *
     * Everything else is refused, most importantly lib/exe/jsonrpc.php and
     * lib/exe/xmlrpc.php (the entire remote API with no per-method gate),
     * feed.php (RSS of recent changes) and lib/exe/openapi.php (discloses the
     * full API surface).
     *
     * @var string[]
     */
    public const ENTRY_SCRIPTS = [
        // the wiki UI itself; narrowed further by ACTS below
        'doku.php',
        // our own MCP endpoint; narrowed further by TOOLS below
        'lib/plugins/reviewqueue/mcp.php',
        // edit locking, draft handling, search suggestions, media browsing;
        // narrowed further by AJAX_CALLS below
        'lib/exe/ajax.php',
        // Media delivery, so media embedded in pages still renders.
        //
        // lib/exe/mediamanager.php is deliberately NOT here, and neither is the
        // 'media' act below. The media manager carries two routes no other gate
        // catches: `mediado=save` reaches core's media_metasave(), which writes
        // IPTC fields straight into the live file, pushes an attic revision and
        // logs a changelog entry without firing MEDIA_UPLOAD_FINISH or
        // MEDIA_DELETE_FILE - so action/media.php never sees it and the change
        // is published unreviewed; and `tab_details=history` renders the media
        // revision list with no rev/at parameter at all, so requestsRevision()
        // cannot catch it either. Refusing the two entry points closes both at
        // once, instead of chasing individual mediado=/tab_details= values.
        //
        // Nothing the operator asked for is lost: the agent reads and writes
        // media through core.listMedia/getMedia/getMediaInfo/saveMedia/
        // deleteMedia on the MCP endpoint, where writes are queued like any
        // other change. What goes is the browser media-manager UI, which only a
        // human placed under review would have used.
        'lib/exe/fetch.php',
        'lib/exe/detail.php',
        // Static assets and service metadata, no wiki content of any kind.
        // These five are exactly the entry scripts that define NOSESSION, so
        // auth_setup() never runs for them and there is no authenticated user
        // to confine in the first place - listing them is bookkeeping, not a
        // decision. Every content-bearing script does authenticate, which is
        // what makes the gate below complete.
        'lib/exe/css.php',
        'lib/exe/js.php',
        'lib/exe/jquery.php',
        'lib/exe/manifest.php',
        'lib/exe/opensearch.php',
    ];

    /**
     * do= actions a review-scoped account may perform.
     *
     * Reading and navigating, plus everything the edit-and-save cycle needs -
     * those edits are what the queue exists to intercept, so they are harmless
     * by construction.
     *
     * Deliberately absent: 'revisions', 'diff' and 'recent' (page history),
     * 'mediadetail' (the media revision table), 'subscribe' (change
     * notification by mail is history through another channel), 'profile' (an
     * agent must not be able to change its own credentials), 'check' and
     * 'resendpwd'.
     *
     * 'search' and 'index' stay by explicit operator decision: without them
     * the agent cannot find pages at all, and neither one exposes revisions.
     *
     * @var string[]
     */
    public const ACTS = [
        // reading and navigating ('media' is absent - see ENTRY_SCRIPTS above:
        // do=media reaches the same media_metasave() and history tab that
        // lib/exe/mediamanager.php does)
        'show', 'search', 'index', 'backlink', 'sitemap',
        // session and error plumbing
        'login', 'logout', 'denied', 'locked', 'redirect', 'draftdel',
        // editing, which is what the queue exists to intercept
        'edit', 'preview', 'save', 'cancel', 'conflict', 'draft',
        // our own review actions (a reviewer is normally not review-scoped,
        // but the two lists can overlap in principle)
        'admin',
    ];

    /**
     * lib/exe/ajax.php call= handlers a review-scoped account may invoke.
     *
     * 'mediadetails' and 'mediadiff' are absent: both serve media revision
     * history, which no other gate would catch because ajax.php never reaches
     * ACTION_ACT_PREPROCESS.
     *
     * @var string[]
     */
    public const AJAX_CALLS = [
        'qsearch', 'suggestions',            // search
        'lock', 'draftdel',                  // edit mechanics
        'index', 'linkwiz',                  // navigation, link picker
        'medians', 'medialist', 'mediaupload', // media browsing and upload
    ];

    /**
     * Remote API methods exposed as MCP tools, by their dotted name.
     *
     * core.savePage and core.appendPage are deliberately absent: the phase 10
     * range write tools replace them. Those tools call ApiCore::savePage()
     * internally (remote.php::writeEffectiveText()), not through Api::call(),
     * so dropping them from the remote surface does not affect them.
     *
     * core.getPage is absent too - plugin.reviewqueue.getPageToEdit supersedes
     * it and is the call ADR-0004 requires an author to use anyway.
     *
     * Everything history-shaped (getPageHistory, getRecentPageChanges,
     * getMediaHistory, getRecentMediaChanges) is absent by the requirement
     * this ADR exists for.
     *
     * @var string[]
     */
    public const TOOLS = [
        // identity
        'core.whoAmI',
        // finding pages
        'core.listPages',
        'core.searchPages',
        // media, read and write; the writes are queued like any other change
        'core.listMedia',
        'core.getMedia',
        'core.getMediaInfo',
        'core.saveMedia',
        'core.deleteMedia',
        // the review workflow itself (ADR-0004, ADR-0006)
        'plugin.reviewqueue.getPageToEdit',
        'plugin.reviewqueue.listMyPending',
        'plugin.reviewqueue.searchMyPending',
        'plugin.reviewqueue.getStatus',
        'plugin.reviewqueue.getPendingText',
        'plugin.reviewqueue.updatePendingChange',
        'plugin.reviewqueue.withdrawPendingChange',
        // range-addressed reads (ADR-0005)
        'plugin.reviewqueue.getPageOutline',
        'plugin.reviewqueue.getSection',
        'plugin.reviewqueue.getLines',
        'plugin.reviewqueue.findInPage',
        'plugin.reviewqueue.searchWithContext',
        // Creating and deleting: the two writes that cannot address an
        // existing range, and together the reason core.savePage is not needed
        // above. Both are deliberate, explicit intents - every range tool
        // refuses to empty a page precisely so a deletion is never accidental.
        'plugin.reviewqueue.createPage',
        'plugin.reviewqueue.deletePage',
        // range-addressed writes (ADR-0005), all queued
        'plugin.reviewqueue.replaceSection',
        'plugin.reviewqueue.insertSection',
        'plugin.reviewqueue.deleteSection',
        'plugin.reviewqueue.replaceLines',
        'plugin.reviewqueue.replaceText',
    ];

    /**
     * Request parameters that address a historical revision.
     *
     * doku.php:45, fetch.php:32 and detail.php:15 all read 'rev'; doku.php
     * additionally resolves 'at' to a revision via getLastRevisionAt(). All of
     * them read it *after* DOKUWIKI_INIT_DONE, which is why one check at that
     * event covers page and media history on every script at once - and why
     * blocking do=revisions alone would look like confinement without being
     * it, since ?rev= leaves the act at 'show'.
     *
     * @var string[]
     */
    public const REVISION_PARAMS = ['rev', 'at'];

    /**
     * Is this account confined at all?
     *
     * Confinement follows review scope exactly: an account whose saves go
     * through the queue is the account that must not be able to route around
     * it. Anyone else is untouched.
     *
     * @param string $user login of the acting user, '' for anonymous
     * @return bool
     */
    public function isConfined($user)
    {
        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');
        return $policy->needsReview($user);
    }

    /**
     * @param string $script path relative to DOKU_INC, e.g. 'lib/exe/fetch.php'
     * @return bool
     */
    public function mayUseEntryScript($script)
    {
        return in_array($script, self::ENTRY_SCRIPTS, true);
    }

    /**
     * @param string $act the do= action
     * @return bool
     */
    public function mayUseAct($act)
    {
        return in_array($act, self::ACTS, true);
    }

    /**
     * @param string $call the ajax.php call= handler name
     * @return bool
     */
    public function mayUseAjaxCall($call)
    {
        return in_array($call, self::AJAX_CALLS, true);
    }

    /**
     * @param string $method dotted remote method name, e.g. 'core.listPages'
     * @return bool
     */
    public function mayUseTool($method)
    {
        return in_array($method, self::TOOLS, true);
    }

    /**
     * Which entry script is serving this request, relative to DOKU_INC.
     *
     * SCRIPT_FILENAME is what PHP actually executes, so it survives rewrite
     * rules that make SCRIPT_NAME unreliable. Anything that cannot be resolved
     * to a path inside DOKU_INC returns '' and the caller must treat that as a
     * refusal, not as permission.
     *
     * @return string relative path, or '' when it cannot be determined
     */
    public function currentEntryScript()
    {
        $file = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if ($file === '') return '';

        $real = realpath($file);
        $root = realpath(DOKU_INC);
        if ($real === false || $root === false) return '';

        // Normalise before comparing, not just on the way out: realpath()
        // returns backslashes on Windows, so a prefix built with a forward
        // slash could never match and this would return '' for every request -
        // which the caller treats as a refusal, locking a review-scoped
        // account out of the entire wiki rather than confining it.
        $real = str_replace('\\', '/', $real);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

        if (strpos($real, $root) !== 0) return '';

        return substr($real, strlen($root));
    }

    /**
     * Does this request ask for a historical revision?
     *
     * rev=0 and an empty at= are what a normal current-revision request looks
     * like, so only a non-empty, non-zero value counts as addressing history.
     *
     * @return bool
     */
    public function requestsRevision()
    {
        global $INPUT;

        foreach (self::REVISION_PARAMS as $param) {
            if (!$INPUT->has($param)) continue;
            $value = trim((string) $INPUT->str($param));
            if ($value !== '' && $value !== '0') return true;
        }

        return false;
    }
}
