<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Remote\RemoteException;

/**
 * Holds back media uploads from review-scoped users, the same way
 * action_plugin_reviewqueue_save holds back page saves.
 *
 * MEDIA_UPLOAD_FINISH is preventable (inc/media.php:501, see
 * docs/research/kaos-hooks.md) and carries the uploaded temp file, its
 * destination and the move function to use.
 *
 * Feedback goes back through $event->result rather than an exception: that is
 * media_save()'s own error channel, an [message, level] pair. Both callers
 * already handle it - ApiCore::saveMedia() turns an array result into a
 * RemoteException the agent can read, and the browser uploader displays it -
 * so one code path serves both without having to tell them apart.
 */
class action_plugin_reviewqueue_media extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'BEFORE', $this, 'handleUpload');
        $controller->register_hook('MEDIA_DELETE_FILE', 'BEFORE', $this, 'handleDelete');
    }

    /**
     * Deleting a media file is a content change like any other, so it goes
     * through the queue too - otherwise a review-scoped account could remove
     * files outright while being unable to add them.
     *
     * MEDIA_DELETE_FILE is preventable (inc/media.php:276). Its caller,
     * media_delete(), reports outcomes as DOKU_MEDIA_* constants rather than
     * messages, so unlike media_save() it offers no result channel to explain
     * the queueing through: a prevented deletion looks to ApiCore::deleteMedia()
     * exactly like a failed one, and the caller got 'Failed to delete media
     * file' for a change that was in fact queued. An agent cannot tell that
     * apart from a real failure and will retry, stacking duplicate entries.
     *
     * So the same throw-as-success-signal convention the page save path uses
     * applies here (ADR-0003): a hard, catchable RemoteException carrying the
     * change number. The browser media manager gets a msg() instead, because a
     * thrown exception would turn into an error page there.
     *
     * That browser message is not the whole output: returning normally leaves
     * media_delete() reporting 0, so lib/exe/mediamanager.php:120 adds its own
     * red "Unable to delete x" underneath ours. Nothing here can suppress it -
     * the only lever the event offers is $data['unl'], and claiming a deletion
     * that did not happen is worse than a redundant notice. It is also not
     * reachable today: a review-scoped account is refused on
     * lib/exe/mediamanager.php outright, and on lib/exe/ajax.php's
     * 'mediadetails' which require_once's it (ADR-0007, helper/capability.php).
     * An operator who widens either allowlist gets the contradictory pair.
     *
     * @throws RemoteException for non-interactive callers, always: queued or
     *                          failed, the deletion did not happen
     */
    public function handleDelete(Event $event, $param)
    {
        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');
        if ($policy->isApplying()) return;
        if (!$policy->reviewMedia()) return;

        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');
        if (!$policy->needsReview($user)) return;

        $event->preventDefault();

        // media_delete() has exactly two callers in Kaos: ApiCore::deleteMedia()
        // and lib/exe/mediamanager.php, which is the only one that defines this
        // constant - so it says which of the two feedback channels below fits.
        $isBrowser = defined('DOKU_MEDIAMANAGER');
        $target = $event->data['id'];

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        try {
            $id = $store->enqueue([
                'type'      => 'media',
                'operation' => 'delete',
                'target'    => $target,
                'author'    => $user,
                'summary'   => '',
                'minor'     => false,
                'baseRev'   => null,
                'baseHash'  => '',
                'origin'    => $isBrowser ? 'ui' : 'remote',
            ], '');
        } catch (\Throwable $e) {
            \dokuwiki\ErrorHandler::logException($e);
            // preventDefault() already stopped the deletion, so failing to
            // queue it still leaves the file in place - fail-closed.
            if ($isBrowser) {
                msg($this->getLang('queue_failed'), -1);
                return;
            }
            throw new RemoteException($this->getLang('queue_failed'), 500, $e);
        }

        $message = sprintf($this->getLang('queued_delete'), $target, $id);
        if ($isBrowser) {
            msg($message);
            return;
        }
        throw new RemoteException($message, 1000);
    }

    public function handleUpload(Event $event, $param)
    {
        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');
        if ($policy->isApplying()) return; // our own approval publishing the file
        if (!$policy->reviewMedia()) return;

        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');
        if (!$policy->needsReview($user)) return;

        [$tmpFile, , $mediaId, $mime, $overwrite] = $event->data;

        $event->preventDefault();

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        try {
            $id = $store->enqueue([
                'type'      => 'media',
                'target'    => $mediaId,
                'author'    => $user,
                'summary'   => '',
                'minor'     => false,
                'baseRev'   => null,
                'baseHash'  => '',
                'origin'    => 'upload',
                'mime'      => $mime,
                'overwrite' => (bool) $overwrite,
            ], '');
            // Copy the upload before returning: DokuWiki cleans the temp file
            // up once this request ends.
            try {
                $store->putMedia($id, $tmpFile);
            } catch (\Throwable $e) {
                // The record exists but its payload does not, so it could
                // never be published. Drop it rather than leave a change in
                // the queue that is guaranteed to fail on approval.
                $store->discard($id);
                throw $e;
            }
        } catch (\Throwable $e) {
            \dokuwiki\ErrorHandler::logException($e);
            // Fail-closed: preventDefault() above already stopped the upload,
            // so nothing is published either way.
            $event->result = [$this->getLang('queue_failed'), -1];
            return;
        }

        $event->result = [sprintf($this->getLang('queued'), $mediaId, $id), 0];
    }
}
