<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * Confines a review-scoped account to the entry scripts it is allowed to use,
 * and refuses any request that addresses a historical revision.
 *
 * DOKUWIKI_INIT_DONE (inc/init.php:245) is the only event that sees every
 * door. Every entry script - doku.php, feed.php, each lib/exe/*.php, and our
 * own mcp.php - opens with require_once(inc/init.php), and auth_setup() runs
 * seven lines earlier at inc/init.php:238, so by the time this fires the
 * acting user is known and *nothing script-specific has run yet*.
 *
 * That last part is what makes the revision check work at all. doku.php:45
 * ($REV = $INPUT->int('rev')), fetch.php:32 and detail.php:15 all read the
 * parameter after this point. Refusing 'rev'/'at' here therefore covers page
 * and media history on every script in one rule - whereas dropping
 * do=revisions from the act allowlist would leave ?rev= wide open, because
 * that request's act is still 'show'.
 *
 * ACTION_ACT_PREPROCESS (action/save.php) cannot do this job: it only ever
 * fires for doku.php, so ajax.php, fetch.php, jsonrpc.php and feed.php never
 * reach it.
 *
 * See docs/design/adr-0007-agent-confinement.md.
 */
class action_plugin_reviewqueue_entrypoint extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('DOKUWIKI_INIT_DONE', 'BEFORE', $this, 'handleInitDone');
    }

    /** @param Event $event */
    public function handleInitDone(Event $event, $param)
    {
        // The CLI has no request to confine and no REMOTE_USER to check. Bail
        // out explicitly rather than relying on needsReview('') being false,
        // so a CLI entry point that does set a user can never be locked out of
        // bin/plugin.php by this gate.
        if (PHP_SAPI === 'cli') return;

        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');

        /** @var helper_plugin_reviewqueue_capability $cap */
        $cap = $this->loadHelper('reviewqueue_capability');
        if (!$cap->isConfined($user)) return;

        $script = $cap->currentEntryScript();

        // An entry script that cannot be resolved to a path inside DOKU_INC is
        // refused rather than trusted. Fail-closed, per CLAUDE.md.
        if ($script === '' || !$cap->mayUseEntryScript($script)) {
            $this->deny(sprintf($this->getLang('entry_denied'), $script !== '' ? $script : '?'));
        }

        // One rule, every allowed script: no historical revisions. This is
        // what actually makes "the agent never sees the history" true.
        if ($cap->requestsRevision()) {
            $this->deny($this->getLang('revision_denied'));
        }

        // ajax.php multiplexes many handlers behind one script, two of which
        // (mediadetails, mediadiff) serve media revision history.
        if ($script === 'lib/exe/ajax.php') {
            $call = $INPUT->str('call');
            if (!$cap->mayUseAjaxCall($call)) {
                $this->deny(sprintf($this->getLang('ajax_denied'), $call !== '' ? $call : '?'));
            }
        }
    }

    /**
     * Refuse the request and stop.
     *
     * Nothing has been rendered at this point, so this is the whole response.
     * A JSON caller gets a JSON-RPC shaped error because that is what it can
     * parse; everything else gets plain text, which is honest for a request
     * that will never produce a page.
     *
     * @param string $message
     * @return never
     */
    protected function deny($message)
    {
        global $INPUT;

        http_status(403);

        $accepts = $INPUT->server->str('HTTP_ACCEPT');
        $sends = $INPUT->server->str('CONTENT_TYPE');
        $wantsJson = strpos($sends, 'application/json') !== false
            || strpos($accepts, 'application/json') !== false;

        if ($wantsJson) {
            header('Content-Type: application/json');
            echo json_encode([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32604, 'message' => $message],
            ], JSON_THROW_ON_ERROR);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $message . "\n";
        }

        exit;
    }
}
