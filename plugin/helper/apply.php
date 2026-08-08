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

        // saveWikiText() does not touch the search index - ApiCore::savePage()
        // calls idx_addPage() itself right after saving, and the browser path
        // relies on the taskrunner firing on a later page view. An approval has
        // neither, so without this the freshly published text stays unfindable
        // until some unrelated request happens to run the indexer.
        idx_addPage($record['target']);
    }
}
