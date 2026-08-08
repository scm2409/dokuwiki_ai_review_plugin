<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Remote\RemoteException;

/**
 * Intercepts page saves from reviewpflichtig users/groups before a revision
 * is written, and queues them instead (see ADR-0001, docs/design/spec.md).
 *
 * COMMON_WIKIPAGE_SAVE (fired inside PageFile::saveWikiText(), see
 * docs/research/kaos-hooks.md) is the single point that covers every
 * caller - the browser edit form, the JSON-RPC/XML-RPC remote API, the MCP
 * plugin, and any other plugin that calls saveWikiText() directly - so all
 * the actual queuing logic lives in handleWikipageSave() alone.
 *
 * ACTION_ACT_PREPROCESS is only used as a cheap, request-scoped marker: it
 * tells handleWikipageSave() whether this request is DokuWiki's own
 * interactive "save" action (a human at the edit form) or anything else
 * (remote API, CLI, another plugin), which decides whether the caller gets
 * a friendly on-page message or a hard RemoteException (see ADR-0003). It
 * deliberately never calls preventDefault() or touches $event->data, so
 * DokuWiki's normal save action still runs to completion - including its
 * own lock cleanup and redirect - even though the actual save ends up
 * being held back downstream.
 */
class action_plugin_reviewqueue_save extends ActionPlugin
{
    /** @var bool */
    protected static $isBrowserSaveAct = false;

    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleActPreprocess');
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'handleWikipageSave');
    }

    /** @param Event $event */
    public function handleActPreprocess(Event $event, $param)
    {
        if ($event->data === 'save') {
            self::$isBrowserSaveAct = true;
        }
    }

    /**
     * @param Event $event
     * @throws RemoteException for non-interactive callers when a save is
     *                          queued or the queue itself fails to write
     */
    public function handleWikipageSave(Event $event, $param)
    {
        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');
        if ($policy->isApplying()) return; // our own approval flow replaying a save

        $data = &$event->data;
        if (!$data['contentChanged']) return; // no-op save, nothing to hold back

        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');
        if (!$policy->needsReview($user)) return;

        if ($data['changeType'] === DOKU_CHANGE_TYPE_DELETE && !$policy->reviewDelete()) return;

        $event->preventDefault();

        $meta = [
            'type'     => 'page',
            'target'   => $data['id'],
            'author'   => $user,
            'summary'  => $data['summary'],
            'minor'    => $data['changeType'] === DOKU_CHANGE_TYPE_MINOR_EDIT,
            'baseRev'  => $data['oldRevision'] ?: null,
            'baseHash' => sha1($data['oldContent']),
            'origin'   => self::$isBrowserSaveAct ? 'ui' : 'remote',
        ];

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        // Look for the author's own still-open changes on this page *before*
        // enqueuing, so the new one isn't counted. Stacking matters: a queued
        // change is invisible in the read path (ADR-0004), so an author who
        // didn't check can easily have based this edit on the live revision
        // and be about to clobber their own earlier, unreviewed work.
        $stacked = $store->listChanges([
            'author' => $user,
            'state'  => 'pending',
            'type'   => 'page',
            'target' => $data['id'],
        ]);

        try {
            $id = $store->enqueue($meta, $data['newContent']);
            $failure = null;
        } catch (\Throwable $e) {
            $id = null;
            $failure = $e;
        }

        if (self::$isBrowserSaveAct) {
            if ($failure) {
                msg($this->getLang('queue_failed'), -1);
            } else {
                msg(sprintf($this->getLang('queued'), $data['id'], $id));
                if ($stacked) {
                    msg(sprintf(
                        $this->getLang('queued_stacked'),
                        '#' . implode(', #', array_column($stacked, 'id'))
                    ), 2);
                }
            }
            return; // fail-closed either way: preventDefault() already ran above
        }

        // Remote API / CLI / another plugin: always a hard, catchable error
        // so nothing mistakes a queued change for a live save (ADR-0003).
        //
        // ApiCore::savePage() wraps the save as lock() -> saveWikiText() ->
        // unlock(), so throwing from inside saveWikiText() skips its unlock()
        // and strands the page lock for the full lock timeout. That would let
        // a busy agent block human editors out of the pages it touches - the
        // exact disruption this plugin exists to avoid. Release it ourselves
        // before throwing. Not needed on the browser path, which returns
        // normally and lets dokuwiki\Action\Save do its own unlock().
        unlock($data['id']);

        if ($failure) {
            throw new RemoteException($this->getLang('queue_failed'), 500, $failure);
        }

        $message = sprintf($this->getLang('queued'), $data['id'], $id);
        if ($stacked) {
            $message .= ' ' . sprintf(
                $this->getLang('queued_stacked'),
                '#' . implode(', #', array_column($stacked, 'id'))
            );
        }
        throw new RemoteException($message, 1000);
    }
}
