<?php

use dokuwiki\Extension\Plugin;

/**
 * Single decision point for "does this save need review" and "may this user
 * review". Every hook and UI component asks here first, so the review rules
 * only ever live in one place. See docs/design/spec.md.
 */
class helper_plugin_reviewqueue_policy extends Plugin
{
    /**
     * Re-entrancy guard: true while helper_plugin_reviewqueue_apply is
     * replaying an approved change through saveWikiText() as the original
     * author, so action_plugin_reviewqueue_save must not queue it again.
     *
     * @var bool
     */
    protected static $applying = false;

    public static function beginApply()
    {
        self::$applying = true;
    }

    public static function endApply()
    {
        self::$applying = false;
    }

    public static function isApplying()
    {
        return self::$applying;
    }

    /**
     * Does a save by this user need to go through the review queue?
     *
     * @param string $user login of the acting user, '' for anonymous
     * @param string[]|null $groups the user's groups; defaults to $USERINFO['grps']
     * @return bool
     */
    public function needsReview($user, ?array $groups = null)
    {
        if ($user === '' || self::$applying) return false;

        if (in_array($user, $this->splitConf('review_users'), true)) return true;

        $groups = $groups ?? $this->currentUserGroups();
        return (bool) array_intersect($groups, $this->splitConf('review_groups'));
    }

    /**
     * May this user approve/reject pending changes?
     *
     * Deliberately does not check needsReview() itself: an operator could in
     * principle put the same login in both lists, and the caller (e.g. the
     * self-approval check in action/review.php) is responsible for that rule.
     *
     * @param string $user
     * @param string[]|null $groups
     * @return bool
     */
    public function isReviewer($user, ?array $groups = null)
    {
        if ($user === '') return false;
        $groups = $groups ?? $this->currentUserGroups();
        return (bool) array_intersect($groups, $this->splitConf('reviewer_groups'));
    }

    public function reviewMedia()
    {
        return (bool) $this->getConf('review_media');
    }

    public function reviewDelete()
    {
        return (bool) $this->getConf('review_delete');
    }

    public function autoMerge()
    {
        return (bool) $this->getConf('auto_merge');
    }

    public function showBanner()
    {
        return (bool) $this->getConf('show_banner');
    }

    protected function currentUserGroups()
    {
        global $USERINFO;
        return $USERINFO['grps'] ?? [];
    }

    protected function splitConf($key)
    {
        $raw = trim((string) $this->getConf($key));
        if ($raw === '') return [];
        return array_map('trim', explode(',', $raw));
    }
}
