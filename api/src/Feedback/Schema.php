<?php

declare(strict_types=1);

namespace MusicRadar\Feedback;

use PDO;
use PDOException;

/**
 * Tables for your feedback on releases (MariaDB). Kept as PHP rather than a
 * .sql file so it ships with the API: on the server the tables are created
 * on first use (ensure()); `php api/bin/db-schema.php --print|--apply` shows
 * or applies the SQL locally.
 *
 * Ready for login later: every row belongs to a user_id (1 = you, for now).
 */
final class Schema
{
    public const STATEMENTS = [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS release_feedback (
          user_id INT UNSIGNED NOT NULL DEFAULT 1,
          release_id CHAR(36) NOT NULL COMMENT 'MusicBrainz release-group id',
          rating TINYINT UNSIGNED NULL COMMENT '1-5 stars, NULL = not rated',
          not_interested TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'hide this release - not a rating',
          saved TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'bookmark only - ignored by the engine',
          release_snapshot LONGTEXT NOT NULL COMMENT 'the release as shown when first marked (JSON)',
          created_at DATETIME NOT NULL,
          updated_at DATETIME NOT NULL,
          PRIMARY KEY (user_id, release_id),
          CONSTRAINT release_feedback_rating CHECK (rating IS NULL OR rating BETWEEN 1 AND 5),
          CONSTRAINT release_feedback_exclusive CHECK (rating IS NULL OR not_interested = 0),
          CONSTRAINT release_feedback_snapshot CHECK (JSON_VALID(release_snapshot))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS release_feedback_log (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          user_id INT UNSIGNED NOT NULL DEFAULT 1,
          release_id CHAR(36) NOT NULL,
          field VARCHAR(16) NOT NULL COMMENT 'rating | not_interested | saved',
          old_value VARCHAR(8) NULL,
          new_value VARCHAR(8) NULL,
          created_at DATETIME NOT NULL,
          INDEX release_feedback_log_release (user_id, release_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    ];

    public static function sql(): string
    {
        return implode(";\n\n", self::STATEMENTS) . ";\n";
    }

    /**
     * Creates the tables on first use if they are missing - so the server
     * (no SSH, no admin tool) sets itself up. Only ever CREATE TABLE IF NOT
     * EXISTS; existing tables and data are never touched. Costs one trivial
     * query per feedback request.
     */
    public static function ensure(PDO $db): void
    {
        try {
            $db->query('SELECT 1 FROM release_feedback_log LIMIT 0');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1146) { // 1146 = table doesn't exist
                throw $e;
            }
            foreach (self::STATEMENTS as $sql) {
                $db->exec($sql);
            }
        }
    }
}
