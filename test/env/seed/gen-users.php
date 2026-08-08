<?php
/**
 * Generates conf/users.auth.php for the test environment using DokuWiki's
 * own smd5 password hash format (crypt() with a '$1$' MD5-crypt salt —
 * see inc/PassHash.php::hash_smd5() in the DokuWiki source).
 *
 * Run once at container build time. Test-environment credentials only,
 * intentionally simple (username == password) — never for production use.
 */

function dwCryptSalt(int $len = 8): string
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $salt = '';
    for ($i = 0; $i < $len; $i++) {
        $salt .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $salt;
}

function dwHash(string $clear): string
{
    return crypt($clear, '$1$' . dwCryptSalt() . '$');
}

// login => [password, real name, email, groups]
$users = [
    'admin'  => ['admin',  'Admin',           'admin@example.test',  'admin,user'],
    'martin' => ['martin', 'Martin Reviewer', 'martin@example.test', 'reviewer,user'],
    'kail'   => ['kail',   'KaiL',            'kail@example.test',   'user'],
];

$lines = [];
foreach ($users as $login => [$password, $name, $email, $groups]) {
    $lines[] = implode(':', [$login, dwHash($password), $name, $email, $groups]);
}

file_put_contents(
    '/var/www/html/conf/users.auth.php',
    "# DokuWiki User Auth Config File\n# Test-environment seed - not for production use.\n" .
    implode("\n", $lines) . "\n"
);

echo "Seeded " . count($users) . " users.\n";
