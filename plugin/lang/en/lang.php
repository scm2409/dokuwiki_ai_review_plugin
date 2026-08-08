<?php

$lang['queued']       = "Your change to '%s' was submitted for review as change #%d. It is NOT live yet.";
$lang['queue_failed'] = 'The review queue could not be written to, so your change was not saved. Please try again or contact an administrator.';

$lang['menu']  = 'Review Queue';
$lang['empty'] = 'There are no pending changes.';
$lang['meta']  = 'By %s: "%s" (%s)';

$lang['comment_label'] = 'Comment (shown to the author, required for rejection)';
$lang['btn_approve']   = 'Approve';
$lang['btn_reject']    = 'Reject';

$lang['approved']         = 'Change #%d approved and published.';
$lang['rejected']         = 'Change #%d rejected.';
$lang['conflicted']       = 'Change #%d could not be approved: the page has changed since this change was submitted.';
$lang['conflict_notice']  = 'The live page has changed since this change was submitted. It cannot be approved automatically.';
$lang['apply_failed']     = 'Something went wrong while applying your decision. Please try again or contact an administrator.';
$lang['not_found']        = 'That pending change no longer exists or has already been decided.';
$lang['no_self_review']   = "You can't approve or reject your own change.";
$lang['approved_summary'] = '(reviewed by %s, change #%d)';

$lang['banner']      = 'There are %d pending change(s) on this page waiting for review.';
$lang['banner_link'] = 'Open the review queue';
