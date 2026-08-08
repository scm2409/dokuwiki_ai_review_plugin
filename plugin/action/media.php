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
            $store->putMedia($id, $tmpFile);
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
