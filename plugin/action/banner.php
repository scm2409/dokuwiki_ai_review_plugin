<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\plugin\reviewqueue\meta\QueueMenuItem;

/**
 * Gives reviewers two ways to discover the review queue: a banner on pages
 * that currently have a pending change, and a permanent "Review Queue"
 * entry in the Site Tools menu (visible to anyone in reviewer_groups, not
 * just DokuWiki managers/admins - see QueueMenuItem).
 *
 * TPL_CONTENT_DISPLAY fires after the page content is rendered but before
 * it's echoed, with $event->data holding the full HTML output by reference
 * (see inc/template.php::tpl_content(), docs/research/kaos-hooks.md) - so
 * prepending to it here is enough, no template override needed.
 */
class action_plugin_reviewqueue_banner extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'handleContentDisplay');
        $controller->register_hook('MENU_ITEMS_ASSEMBLY', 'AFTER', $this, 'handleMenuAssembly');
    }

    /**
     * Adds a "Review Queue" entry to the Site Tools menu for reviewers.
     *
     * DokuWiki's own "Admin" menu entry (inc/Menu/Item/Admin.php) only
     * shows for $INFO['ismanager'], which a reviewer in a dedicated group
     * (not DokuWiki admin/manager) never is - without this, there would be
     * no link to the queue anywhere except a banner on a page that already
     * happens to have a pending change on it.
     */
    public function handleMenuAssembly(Event $event, $param)
    {
        global $INPUT;

        if ($event->data['view'] !== 'site') return;

        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');
        if (!$policy->isReviewer($INPUT->server->str('REMOTE_USER'))) return;

        $event->data['items'][] = new QueueMenuItem();
    }

    public function handleContentDisplay(Event $event, $param)
    {
        global $ACT, $ID, $INPUT;

        if ($ACT !== 'show') return;

        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');
        if (!$policy->showBanner()) return;

        $user = $INPUT->server->str('REMOTE_USER');
        if (!$policy->mayReviewTarget($user, $ID)) return;

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');

        // Conflicted changes count too: they are still outstanding work on
        // this page, and they are the ones most in need of attention.
        $pending = array_filter(
            $store->listChanges(['type' => 'page', 'target' => $ID]),
            static fn($r) => in_array($r['state'], ['pending', 'conflicted'], true)
        );
        if (!$pending) return;

        $url = wl($ID, ['do' => 'admin', 'page' => 'reviewqueue'], true, '&');
        $banner = '<div class="reviewqueue-banner"><p>' .
            sprintf(hsc($this->getLang('banner')), count($pending)) .
            ' <a href="' . $url . '">' . hsc($this->getLang('banner_link')) . '</a></p></div>';

        $event->data = $banner . $event->data;
    }
}
