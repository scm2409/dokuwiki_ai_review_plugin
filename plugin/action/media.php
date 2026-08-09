<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

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
     * messages, so there is no channel to explain the queueing here; the
     * agent-facing explanation comes from the queue tools instead.
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

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        try {
            $store->enqueue([
                'type'      => 'media',
                'operation' => 'delete',
                'target'    => $event->data['id'],
                'author'    => $user,
                'summary'   => '',
                'minor'     => false,
                'baseRev'   => null,
                'baseHash'  => '',
                'origin'    => 'remote',
            ], '');
        } catch (\Throwable $e) {
            \dokuwiki\ErrorHandler::logException($e);
            // preventDefault() already stopped the deletion, so failing to
            // queue it still leaves the file in place - fail-closed.
        }
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
