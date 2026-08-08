<?php

namespace dokuwiki\plugin\reviewqueue\meta;

use dokuwiki\Menu\Item\AbstractItem;

/**
 * Site-tools link to the review queue admin page. Only added to the menu
 * for reviewers - see action_plugin_reviewqueue_banner::handleMenuAssembly().
 *
 * This is the actual fix for the gap found while testing: DokuWiki's own
 * "Admin" link (inc/Menu/Item/Admin.php) only shows for $INFO['ismanager'],
 * which a reviewer in a dedicated 'reviewer' group never is (see ADR on
 * reviewer_groups in docs/design/spec.md) - without this, a reviewer had no
 * way to discover the queue at all except a banner on a page that already
 * happens to have a pending change.
 */
class QueueMenuItem extends AbstractItem
{
    public function __construct()
    {
        parent::__construct();

        $this->id = '';
        $this->params = ['do' => 'admin', 'page' => 'reviewqueue'];
        $this->svg = DOKU_INC . 'lib/images/menu/settings.svg';

        /** @var \helper_plugin_reviewqueue_policy $policy */
        $policy = plugin_load('helper', 'reviewqueue_policy');
        $this->label = $policy->getLang('menu');
    }
}
