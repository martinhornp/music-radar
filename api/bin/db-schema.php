<?php

declare(strict_types=1);

/**
 * Feedback tables (see MusicRadar\Feedback\Schema).
 *
 *   php api/bin/db-schema.php --print   SQL to paste into phpMyAdmin (one.com)
 *   php api/bin/db-schema.php --apply   create the tables in the configured
 *                                       database (local Docker); safe to repeat
 */

use MusicRadar\Database\Database;
use MusicRadar\Feedback\Schema;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';

$mode = $argv[1] ?? '--print';
if ($mode === '--print') {
    echo Schema::sql();
    exit(0);
}
if ($mode !== '--apply') {
    fwrite(STDERR, "Usage: db-schema.php --print | --apply\n");
    exit(2);
}
if (!Database::isConfigured($app->config['db'])) {
    fwrite(STDERR, "No database configured (config.local.php or DB_* environment variables).\n");
    exit(1);
}
$db = Database::connect($app->config['db']);
foreach (Schema::STATEMENTS as $sql) {
    $db->exec($sql);
}
echo "Feedback tables are in place in database '{$app->config['db']['name']}'.\n";
