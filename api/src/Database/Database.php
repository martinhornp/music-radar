<?php

declare(strict_types=1);

namespace MusicRadar\Database;

use PDO;

/**
 * MariaDB connection from config['db'] (config.local.php on the server,
 * DB_* environment variables in Docker). Credentials never leave this class.
 */
final class Database
{
    public static function isConfigured(array $db): bool
    {
        return ($db['name'] ?? '') !== '' && ($db['user'] ?? '') !== '';
    }

    public static function connect(array $db): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int) $db['port'], $db['name']);
        return new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }
}
