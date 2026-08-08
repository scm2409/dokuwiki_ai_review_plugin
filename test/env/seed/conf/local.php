<?php
/**
 * DokuWiki test-environment configuration.
 * Seeded at container build time — not for production use.
 */

$conf['title']          = 'AI Review Queue Test Wiki';
$conf['lang']            = 'en';
$conf['savedir']         = './data';

$conf['useacl']          = 1;
$conf['authtype']        = 'authplain';
$conf['passcrypt']       = 'smd5';
$conf['defaultgroup']    = 'user';
$conf['superuser']       = '@admin';
$conf['manager']         = '@admin';

$conf['remote']          = 1;
$conf['remoteuser']      = '';
$conf['remotecors']      = '*';

$conf['useheading']      = 1;
$conf['userewrite']      = 0;
$conf['send404']         = 0;

// reviewqueue: kail (the AI agent account) is the only user whose saves are
// held back; martin reviews via a dedicated group, not DokuWiki admin rights
$conf['plugin']['reviewqueue']['review_users']    = 'kail';
$conf['plugin']['reviewqueue']['reviewer_groups'] = 'reviewer';
