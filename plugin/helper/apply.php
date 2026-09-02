<?php

use dokuwiki\Extension\Plugin;

/**
 * Applies a reviewer's decision to a pending change: replays an approved
 * save as the original author, or archives a rejection without touching
 * the page. See docs/design/spec.md, "Freigabe-Ablauf".
 */
class helper_plugin_reviewqueue_apply extends Plugin
{
    /**
     * Approve a pending change.
     *
     * If the live page has changed since the change was queued (its
     * content no longer matches the pending change's baseHash), the
     * change is marked 'conflicted' instead of being applied - Phase 6
     * adds the actual 3-way merge attempt and a manual resolution UI.
     *
     * @param array $record as returned by helper_plugin_reviewqueue_store::get()
     * @param string $reviewer login of the approving user
     * @return string 'approved' or 'conflicted'
     * @throws \RuntimeException on any storage or save failure
     */
    public function approve(array $record, $reviewer)
    {
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        $mergeResult = 'clean';

        if ($record['type'] === 'page') {
            $content = $store->getContent($record['id']);
            $current = rawWiki($record['target']);

            if (sha1($current) !== $record['baseHash']) {
                // The page moved on since this change was written. Try to
                // reconcile the two rather than discarding either.
                $merged = $this->tryMerge($record, $current, $content);
                if (!$merged) {
                    $record['state'] = 'conflicted';
                    $store->update($record);
                    return 'conflicted';
                }
                $content = $merged;
                $mergeResult = 'auto-merged';
            }

            $this->replaySave($record, $content, $this->summaryFor($record, $reviewer));
        } elseif ($record['type'] === 'media') {
            $this->replayMediaSave($record);
        }

        $record['state']       = 'approved';
        $record['reviewer']    = $reviewer;
        $record['reviewedAt']  = time();
        $record['mergeResult'] = $mergeResult;
        $store->update($record);
        $store->archive($record['id']);

        return 'approved';
    }

    /**
     * Publish a conflicted change using text a reviewer resolved by hand.
     *
     * No base-hash check here: the reviewer looked at the conflict and decided
     * what the page should say, so their text wins outright.
     *
     * @param array $record
     * @param string $reviewer
     * @param string $text the resolved wiki text
     * @throws \RuntimeException on any storage or save failure
     */
    public function resolve(array $record, $reviewer, $text)
    {
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        $this->replaySave($record, $text, $this->summaryFor($record, $reviewer));

        $record['state']       = 'approved';
        $record['reviewer']    = $reviewer;
        $record['reviewedAt']  = time();
        $record['mergeResult'] = 'manual';
        $store->update($record);
        $store->archive($record['id']);
    }

    /**
     * Attempt a clean three-way merge.
     *
     * @param array $record
     * @param string $current live page text
     * @param string $pending proposed text
     * @return string|null merged text, or null when it cannot be merged
     *                     cleanly and a human has to decide
     */
    protected function tryMerge(array $record, $current, $pending)
    {
        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');
        if (!$policy->autoMerge()) return null;

        /** @var helper_plugin_reviewqueue_merge $merge */
        $merge = $this->loadHelper('reviewqueue_merge');

        $base = $merge->baseText($record);
        if ($base === null) return null; // base revision unavailable

        $result = $merge->merge($base, $current, $pending);
        return $result['conflicts'] === 0 ? $result['text'] : null;
    }

    /**
     * @param array $record
     * @param string $reviewer
     * @return string
     */
    protected function summaryFor(array $record, $reviewer)
    {
        $note = sprintf($this->getLang('approved_summary'), $reviewer, $record['id']);
        $own = trim((string) $record['summary']);
        return $own === '' ? $note : $own . ' ' . $note;
    }

    /**
     * Reject a pending change. Nothing is written to the page.
     *
     * @param array $record
     * @param string $reviewer login of the rejecting user
     * @param string $comment reason shown to the original author
     * @throws \RuntimeException on any storage failure
     */
    public function reject(array $record, $reviewer, $comment)
    {
        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        $record['state']      = 'rejected';
        $record['reviewer']   = $reviewer;
        $record['reviewedAt'] = time();
        $record['comment']    = $comment;
        $store->update($record);
        $store->archive($record['id']);
    }

    /**
     * Replay a save as the pending change's original author, so the
     * resulting revision is correctly attributed rather than to the
     * reviewer. Guarded by the policy re-entrancy flag so
     * action_plugin_reviewqueue_save doesn't queue this save right back.
     *
     * @param array $record
     * @param string $content
     * @param string $summary
     */
    protected function replaySave(array $record, $content, $summary)
    {
        global $INPUT;

        $originalUser = $INPUT->server->str('REMOTE_USER');
        $INPUT->server->set('REMOTE_USER', $record['author']);
        helper_plugin_reviewqueue_policy::beginApply();
        try {
            saveWikiText($record['target'], $content, $summary, (bool) $record['minor']);
        } finally {
            helper_plugin_reviewqueue_policy::endApply();
            $INPUT->server->set('REMOTE_USER', $originalUser);
        }

        $this->reindex($record['target']);
    }

    /**
     * Publish a queued upload by handing the stored copy back to DokuWiki's
     * own media_save(), so permission checks, mime validation, overwrite
     * handling and the media changelog all behave exactly as for a direct
     * upload - attributed to the original uploader.
     *
     * 'copy' rather than 'rename' as the move function: the queued file stays
     * put so it can be archived alongside the change record.
     *
     * @param array $record
     * @throws \RuntimeException when the upload cannot be published
     */
    protected function replayMediaSave(array $record)
    {
        global $INPUT;

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        if (($record['operation'] ?? 'upload') === 'delete') {
            $this->replayMediaDelete($record);
            return;
        }

        $path = $store->mediaPath($record['id']);
        if ($path === null) {
            throw new \RuntimeException("reviewqueue: stored upload for #{$record['id']} is missing");
        }

        $originalUser = $INPUT->server->str('REMOTE_USER');
        $INPUT->server->set('REMOTE_USER', $record['author']);
        helper_plugin_reviewqueue_policy::beginApply();
        try {
            $res = media_save(
                ['name' => $path, 'mime' => $record['mime'] ?? null],
                $record['target'],
                !empty($record['overwrite']),
                AUTH_UPLOAD,
                'copy'
            );
        } finally {
            helper_plugin_reviewqueue_policy::endApply();
            $INPUT->server->set('REMOTE_USER', $originalUser);
        }

        // media_save() reports failure as an [message, level] pair.
        if (is_array($res)) {
            throw new \RuntimeException('reviewqueue: media_save failed: ' . $res[0]);
        }
    }

    /**
     * Carry out an approved media deletion as the original requester.
     *
     * @param array $record
     * @throws \RuntimeException when the deletion is refused
     */
    protected function replayMediaDelete(array $record)
    {
        global $INPUT;

        $originalUser = $INPUT->server->str('REMOTE_USER');
        $INPUT->server->set('REMOTE_USER', $record['author']);
        helper_plugin_reviewqueue_policy::beginApply();
        try {
            $res = media_delete($record['target'], AUTH_DELETE);
        } finally {
            helper_plugin_reviewqueue_policy::endApply();
            $INPUT->server->set('REMOTE_USER', $originalUser);
        }

        // media_delete() reports success as a bitmask, not a single value: it
        // returns DOKU_MEDIA_DELETED | DOKU_MEDIA_EMPTY_NS when the file was
        // the last one in its namespace (inc/media.php:296). Comparing the
        // whole value therefore called a successful deletion a failure - and
        // there is no DOKU_MEDIA_NOT_EXIST constant to compare against either
        // (inc/defines.php:63-66 defines exactly four), so "already gone" has
        // to be read off the filesystem. Test the bit, then the file.
        if (!($res & DOKU_MEDIA_DELETED) && media_exists($record['target'])) {
            throw new \RuntimeException("reviewqueue: media_delete refused ({$res})");
        }
    }

    /**
     * @param string $page
     */
    protected function reindex($page)
    {
        // saveWikiText() does not touch the search index - ApiCore::savePage()
        // calls idx_addPage() itself right after saving, and the browser path
        // relies on the taskrunner firing on a later page view. An approval has
        // neither, so without this the freshly published text stays unfindable
        // until some unrelated request happens to run the indexer.
        idx_addPage($page);
    }
}
