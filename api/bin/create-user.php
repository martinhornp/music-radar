<?php

declare(strict_types=1);

/**
 * Creates the one login user (martin) in the configured database - once:
 * nothing happens if a user exists. The password is read from standard
 * input, never from the command line or the code, so it is not in shell
 * history, Git or logs.
 *
 *   docker compose exec php php /var/www/api/bin/create-user.php
 *
 * On the server (no SSH): the "Initialize login" GitHub workflow, with the
 * password as the GitHub secret INITIAL_PASSWORD.
 */

use MusicRadar\Auth\Auth;
use MusicRadar\Database\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
fwrite(STDERR, 'Password for martin: ');
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($password === '') {
    fwrite(STDERR, "\nNo password given - nothing created.\n");
    exit(1);
}
$auth = new Auth(Database::connect($app->config['db']));
$auth->ensure();
$created = $auth->createInitialUser('martin', $password, time());
fwrite(STDERR, "\n" . ($created ? "User martin created.\n" : "A user exists already - nothing changed.\n"));
exit($created ? 0 : 1);
