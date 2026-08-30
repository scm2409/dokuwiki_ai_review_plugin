<?php

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;

/**
 * Review queue admin page: lists pending changes with a diff against the
 * current live page, and lets reviewers approve or reject them.
 *
 * handle()/html() are called by dokuwiki\Action\Admin::preProcess() for
 * every request to this admin page (see docs/research/kaos-hooks.md) -
 * handle() processes an approve/reject POST first, html() then renders the
 * (possibly now-shorter) queue. Access is gated by isReviewer(), not
 * DokuWiki's own admin/manager rights (see ADR on reviewer_groups in
 * docs/design/spec.md).
 */
class admin_plugin_reviewqueue extends AdminPlugin
{
    /** @var helper_plugin_reviewqueue_policy */
    protected $policy;
    /** @var helper_plugin_reviewqueue_store */
    protected $store;

    public function __construct()
    {
        $this->policy = $this->loadHelper('reviewqueue_policy');
        $this->store  = $this->loadHelper('reviewqueue_store');
    }

    public function forAdminOnly()
    {
        return false; // gated by isAccessibleByCurrentUser() below instead
    }

    public function isAccessibleByCurrentUser()
    {
        global $INPUT;
        return $this->policy->isReviewer($INPUT->server->str('REMOTE_USER'));
    }

    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    public function handle()
    {
        global $INPUT, $ID;

        $rqaction = $INPUT->str('rqaction');
        if ($rqaction === '' || !checkSecurityToken()) return;

        $id = $INPUT->int('rqid');
        $record = $this->store->get($id);
        $user = $INPUT->server->str('REMOTE_USER');

        // 'resolve' is by definition the follow-up to a failed approval, so it
        // acts on a conflicted change; the others act on an open one. Either
        // way a change that has already been decided is off limits, which also
        // makes a double-submitted form harmless.
        $allowed = $rqaction === 'resolve' ? ['conflicted'] : ['pending', 'conflicted'];
        if (!$record || !in_array($record['state'], $allowed, true)) {
            msg($this->getLang('not_found'), -1);
            return;
        }
        if ($record['author'] === $user) {
            msg($this->getLang('no_self_review'), -1);
            return;
        }
        if (!$this->policy->mayReviewTarget($user, $record['target'], $record['type'])) {
            msg($this->getLang('not_found'), -1);
            return;
        }

        /** @var helper_plugin_reviewqueue_apply $apply */
        $apply = $this->loadHelper('reviewqueue_apply');

        try {
            if ($rqaction === 'approve') {
                $result = $apply->approve($record, $user);
                if ($result === 'conflicted') {
                    msg(sprintf($this->getLang('conflicted'), $id), -1);
                } else {
                    msg(sprintf($this->getLang('approved'), $id), 1);
                }
            } elseif ($rqaction === 'reject') {
                $apply->reject($record, $user, $INPUT->str('rqcomment'));
                msg(sprintf($this->getLang('rejected'), $id), 1);
            } elseif ($rqaction === 'resolve') {
                $text = $INPUT->post->str('rqtext');
                if (str_contains($text, helper_plugin_reviewqueue_merge::MARK_SPLIT)) {
                    // Publishing conflict markers into the wiki is never what
                    // the reviewer meant; almost always they missed a block.
                    msg($this->getLang('markers_left'), -1);
                    return;
                }
                $apply->resolve($record, $user, $text);
                msg(sprintf($this->getLang('resolved'), $id), 1);
            }
        } catch (\Throwable $e) {
            // The reviewer gets a plain message, but swallowing the cause
            // outright makes these failures undiagnosable - hand it to
            // DokuWiki's error log the way core does.
            \dokuwiki\ErrorHandler::logException($e);
            msg($this->getLang('apply_failed'), -1);
        }

        send_redirect(wl($ID, ['do' => 'admin', 'page' => 'reviewqueue'], true, '&'));
    }

    public function html()
    {
        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';

        global $INPUT;
        $user = $INPUT->server->str('REMOTE_USER');

        $records = $this->store->listChanges();
        $open = array_filter($records, function ($r) use ($user) {
            if (!in_array($r['state'], ['pending', 'conflicted'], true)) return false;
            // Don't list changes to pages this reviewer may not read - the
            // queue must not become a way around the wiki's own ACLs.
            return $this->policy->mayReviewTarget($user, $r['target'], $r['type']);
        });

        if (!$open) {
            echo '<p>' . hsc($this->getLang('empty')) . '</p>';
            return;
        }

        // Several open changes on the same page are each based on the live
        // revision rather than on each other (a queued change is invisible in
        // the read path, ADR-0004), so approving more than one silently
        // overwrites the earlier ones. Warn per affected page.
        $stacked = [];
        foreach ($open as $record) {
            $stacked[$record['target']][] = $record['id'];
        }

        foreach ($open as $record) {
            $siblings = $stacked[$record['target']];
            $this->renderRecord($record, count($siblings) > 1 ? $siblings : []);
        }
    }

    /**
     * @param array $record
     * @param int[] $siblings ids of all open changes on the same page, when
     *                        there is more than one; empty otherwise
     */
    protected function renderRecord(array $record, array $siblings = [])
    {
        $id = $record['id'];

        echo '<div class="reviewqueue-item" data-rqid="' . $id . '">';
        echo '<h2>#' . $id . ' &mdash; ' . hsc($record['target']) . '</h2>';

        if ($siblings) {
            echo '<p class="reviewqueue-stacked">' . sprintf(
                hsc($this->getLang('stacked_notice')),
                count($siblings),
                hsc(implode(', #', $siblings))
            ) . '</p>';
        }
        if (!empty($record['updateCount'])) {
            // The author continued this change in place (see
            // docs/design/adr-0006) instead of stacking a new one - flag it
            // so a reviewer who looked at it earlier knows the text has
            // moved on since.
            echo '<p class="reviewqueue-updated">' . sprintf(
                hsc($this->getLang('updated_notice')),
                $record['updateCount'],
                dformat($record['updated'])
            ) . '</p>';
        }
        echo '<p>' . sprintf(
            hsc($this->getLang('meta')),
            hsc($record['author']),
            hsc($record['summary']),
            dformat($record['created'])
        ) . '</p>';

        if ($record['type'] === 'page') {
            $this->renderDiffAndPreview($record);
        } else {
            $this->renderMedia($record);
        }

        if ($record['state'] === 'conflicted') {
            $this->renderConflict($record);
        } else {
            $this->renderForm($record);
        }

        echo '</div>';
    }

    /**
     * A conflicted change cannot be published as-is, so instead of the plain
     * approve button the reviewer gets the merged text with conflict markers
     * to edit down to what the page should actually say.
     *
     * The merge is recomputed here rather than stored at conflict time: the
     * live page may have changed again since, and resolving against a stale
     * merge would quietly undo whatever happened in between.
     */
    protected function renderConflict(array $record)
    {
        echo '<p class="reviewqueue-conflict">' . hsc($this->getLang('conflict_notice')) . '</p>';

        /** @var helper_plugin_reviewqueue_merge $merge */
        $merge = $this->loadHelper('reviewqueue_merge');

        $base = $merge->baseText($record);
        $live = rawWiki($record['target']);
        $pending = $this->store->getContent($record['id']);

        if ($base === null) {
            // No usable base revision, so a three-way merge is impossible.
            // Offer the proposed text on its own and say so plainly.
            echo '<p class="reviewqueue-conflict">' . hsc($this->getLang('no_base')) . '</p>';
            $text = $pending;
        } else {
            $text = $merge->merge($base, $live, $pending)['text'];
        }

        $form = new Form(['method' => 'POST']);
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'reviewqueue');
        $form->setHiddenField('rqid', $record['id']);
        $form->addTextarea('rqtext', $this->getLang('resolve_label'))
             ->val($text)
             ->attr('rows', '20')
             ->addClass('reviewqueue-resolve');
        $form->addButton('rqaction', $this->getLang('btn_resolve'))->attr('value', 'resolve');
        $form->addButton('rqaction', $this->getLang('btn_reject'))->attr('value', 'reject');
        echo $form->toHTML();
    }

    /**
     * There is no meaningful diff for a binary, so describe the upload
     * instead: what it would land on, how big it is, and whether it would
     * replace something that already exists.
     */
    protected function renderMedia(array $record)
    {
        if (($record['operation'] ?? 'upload') === 'delete') {
            echo '<p class="reviewqueue-media reviewqueue-conflict">' .
                hsc($this->getLang('media_delete')) . '</p>';
            return;
        }

        $path = $this->store->mediaPath($record['id']);
        $size = $path ? filesize($path) : 0;

        echo '<p class="reviewqueue-media">' . sprintf(
            hsc($this->getLang('media_info')),
            hsc($record['mime'] ?? ''),
            hsc(filesize_h($size))
        ) . '</p>';

        if (!empty($record['overwrite']) && media_exists($record['target'])) {
            echo '<p class="reviewqueue-conflict">' .
                hsc($this->getLang('media_overwrite')) . '</p>';
        }
    }

    /**
     * A source diff and a rendered preview answer different questions
     * ("what changed" vs. "how would it look"), so a reviewer switches
     * between them rather than scrolling past one to reach the other - CSS
     * radio-button tabs, no JS needed. Both panels stay in the DOM (just
     * display:none'd via CSS, see style.css) so this works with core CSS
     * only and needs no dedicated markup per record beyond a unique
     * radio-group name; the reviewer's tab choice does not need to persist
     * across page loads.
     *
     * @param array $record
     */
    protected function renderDiffAndPreview(array $record)
    {
        $id = $record['id'];
        $diffTab = 'rqtab-diff-' . $id;
        $previewTab = 'rqtab-preview-' . $id;

        echo '<div class="reviewqueue-tabs">';
        echo '<input type="radio" class="reviewqueue-tab-radio" name="rqtab-' . $id . '" id="' .
            $diffTab . '" checked>';
        echo '<label class="reviewqueue-tab-label" for="' . $diffTab . '">' .
            hsc($this->getLang('diff_label')) . '</label>';
        echo '<input type="radio" class="reviewqueue-tab-radio" name="rqtab-' . $id . '" id="' .
            $previewTab . '">';
        echo '<label class="reviewqueue-tab-label" for="' . $previewTab . '">' .
            hsc($this->getLang('preview_label')) . '</label>';

        echo '<div class="reviewqueue-tabpanel reviewqueue-tabpanel-diff">';
        $this->renderDiff($record);
        echo '</div>';

        echo '<div class="reviewqueue-tabpanel reviewqueue-tabpanel-preview">';
        $this->renderPreview($record);
        echo '</div>';

        echo '</div>';
    }

    protected function renderDiff(array $record)
    {
        $old = explode("\n", rawWiki($record['target']));
        $new = explode("\n", $this->store->getContent($record['id']));

        $diff = new \Diff($old, $new);
        $formatter = new \TableDiffFormatter();

        // The diff table's columns don't wrap (each is essentially a <pre>
        // line), so a long line makes the table wider than the page with no
        // way to reach the rest of it - give it its own horizontal scrollbar
        // instead of relying on (or overflowing past) the page's.
        echo '<div class="reviewqueue-scroll"><table class="diff">' .
            $formatter->format($diff) . '</table></div>';
    }

    /**
     * Rendered read-only, on demand, gated by the same isReviewer() check as
     * the rest of this page - this does not reopen the read-path question
     * from ADR-0004 (no unreviewed content reaches rendering, search, or any
     * other author-facing path).
     */
    protected function renderPreview(array $record)
    {
        $text = $this->store->getContent($record['id']);

        echo '<div class="reviewqueue-scroll reviewqueue-preview-content">';
        echo $text === '' ? hsc($this->getLang('preview_delete')) : $this->renderAs($record['target'], $text);
        echo '</div>';
    }

    /**
     * Rendering (p_render(), via the xhtml Doku_Renderer) resolves relative
     * links, media, and includes against the global $ID of the current
     * request - which here is the admin page ('reviewqueue'), not the page
     * the pending text actually belongs to. Same fix as helper/apply.php
     * uses for REMOTE_USER during replaySave(): swap the global for the
     * duration of the call, always restore it.
     *
     * p_render_text() would be the usual one-line shortcut for this, but it
     * doesn't exist in Kaos (verified against the running container) -
     * calling p_render() with p_get_instructions() directly is what it
     * wraps in later DokuWiki releases.
     *
     * @param string $target page id the text belongs to
     * @param string $text wiki text to render
     * @return string rendered XHTML
     */
    protected function renderAs($target, $text)
    {
        global $ID;

        $original = $ID;
        $ID = $target;
        try {
            $info = [];
            return (string) p_render('xhtml', p_get_instructions($text), $info);
        } finally {
            $ID = $original;
        }
    }

    protected function renderForm(array $record)
    {
        $form = new Form(['method' => 'POST']);
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'reviewqueue');
        $form->setHiddenField('rqid', $record['id']);
        $form->addTextInput('rqcomment', $this->getLang('comment_label'));
        $form->addButton('rqaction', $this->getLang('btn_approve'))->attr('value', 'approve');
        $form->addButton('rqaction', $this->getLang('btn_reject'))->attr('value', 'reject');
        echo $form->toHTML();
    }
}
