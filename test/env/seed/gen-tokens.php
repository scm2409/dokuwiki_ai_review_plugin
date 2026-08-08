#!/usr/bin/env php
<?php
/**
 * Prints JSON {login: token} API tokens for the given DokuWiki usernames,
 * using the same dokuwiki\JWT class the web UI's profile page uses
 * (inc/Ui/UserProfile.php -> JWT::fromUser()). Run inside the container
 * after startup, e.g. via `podman exec`.
 */

// Fixed path: this script is copied to a well-known location inside the
// test container image (see Containerfile), independent of its source
// location in the repo.
define('DOKU_INC', '/var/www/html/');
define('NOSESSION', 1);
require_once(DOKU_INC . 'inc/init.php');

$logins = array_slice($argv, 1);
if (!$logins) {
    fwrite(STDERR, "Usage: gen-tokens.php <login> [<login> ...]\n");
    exit(1);
}

$tokens = [];
foreach ($logins as $login) {
    $tokens[$login] = \dokuwiki\JWT::fromUser($login)->getToken();
}

echo json_encode($tokens, JSON_PRETTY_PRINT) . "\n";
