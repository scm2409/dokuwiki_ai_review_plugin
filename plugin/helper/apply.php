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

        if ($record['type'] === 'page') {
            $current = rawWiki($record['target']);
            if (sha1($current) !== $record['baseHash']) {
                $record['state'] = 'conflicted';
                $store->update($record);
                return 'conflicted';
            }

            $content = $store->getContent($record['id']);
            $summary = sprintf($this->getLang('approved_summary'), $reviewer, $record['id']);
            if (trim((string) $record['summary']) !== '') {
                $summary = $record['summary'] . ' ' . $summary;
            }

            $this->replaySave($record, $content, $summary);
        }

        $record['state']       = 'approved';
        $record['reviewer']    = $reviewer;
        $record['reviewedAt']  = time();
        $record['mergeResult'] = 'clean';
        $store->update($record);
        $store->archive($record['id']);

        return 'approved';
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
    }
}
