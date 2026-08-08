<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

/**
 * Shows reviewers a banner on pages that have pending changes waiting.
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
    }

    public function handleContentDisplay(Event $event, $param)
    {
        global $ACT, $ID, $INPUT;

        if ($ACT !== 'show') return;

        /** @var helper_plugin_reviewqueue_policy $policy */
        $policy = $this->loadHelper('reviewqueue_policy');
        if (!$policy->showBanner()) return;

        $user = $INPUT->server->str('REMOTE_USER');
        if (!$policy->isReviewer($user)) return;

        /** @var helper_plugin_reviewqueue_store $store */
        $store = $this->loadHelper('reviewqueue_store');
        $pending = $store->listChanges(['type' => 'page', 'target' => $ID, 'state' => 'pending']);
        if (!$pending) return;

        $url = wl($ID, ['do' => 'admin', 'page' => 'reviewqueue'], true, '&');
        $banner = '<div class="reviewqueue-banner"><p>' .
            sprintf(hsc($this->getLang('banner')), count($pending)) .
            ' <a href="' . $url . '">' . hsc($this->getLang('banner_link')) . '</a></p></div>';

        $event->data = $banner . $event->data;
    }
}
