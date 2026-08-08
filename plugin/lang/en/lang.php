<?php

$lang['queued']         = "Your change to '%s' was submitted for review as change #%d. It is NOT live yet.";
$lang['queued_stacked'] = 'Warning: you already have unreviewed change(s) %s on this page. This new change was based on the live revision, not on those - if all of them are approved, the earlier work will be overwritten.';
$lang['queue_failed']   = 'The review queue could not be written to, so your change was not saved. Please try again or contact an administrator.';

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

$lang['stacked_notice'] = 'Careful: there are %d unreviewed changes for this page (#%s), each based on the live revision rather than on each other. Approving more than one will overwrite the earlier ones. Review them one at a time and reject the ones you do not want.';

$lang['resolved']      = 'Change #%d resolved and published.';
$lang['resolve_label'] = 'Resolved page text (remove the conflict markers and leave what the page should say)';
$lang['btn_resolve']   = 'Publish resolved text';
$lang['markers_left']  = 'The text still contains conflict markers. Remove them and keep only the wording the page should have.';
$lang['no_base']       = 'The revision this change was written against is no longer available, so it cannot be merged automatically. Below is the proposed text on its own - compare it against the current page yourself.';

$lang['media_info']      = 'Uploaded file: %s, %s.';
$lang['media_overwrite'] = 'This upload would replace a file that already exists.';
