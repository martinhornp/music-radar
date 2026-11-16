<?php

declare(strict_types=1);

namespace MusicRadar\Import;

use PDO;

/**
 * One row per import call (the daily import is many short calls), for
 * monitoring: when it ran, how long, what it did, and errors - never
 * secrets (messages are redacted before they get here).
 */
final class ImportLog
{
    private const KEEP = 1000;

    public function __construct(private readonly PDO $db)
    {
    }

    public function install(): void
    {
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->db->exec('CREATE TABLE IF NOT EXISTS import_log (
            id ' . ($mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ',
            started_at VARCHAR(25) NOT NULL, seconds REAL NULL, status VARCHAR(16) NOT NULL,
            summary ' . ($mysql ? 'MEDIUMTEXT' : 'TEXT') . ' NULL, error TEXT NULL
        )' . ($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    public function add(string $startedAt, float $seconds, string $status, array $summary, ?string $error): void
    {
        $this->db->prepare('INSERT INTO import_log (started_at, seconds, status, summary, error) VALUES (?, ?, ?, ?, ?)')
            ->execute([$startedAt, round($seconds, 1), $status, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $error]);
        $id = (int) $this->db->lastInsertId();
        if ($id % 50 === 0) {
            $this->db->prepare('DELETE FROM import_log WHERE id <= ?')->execute([$id - self::KEEP]);
        }
    }

    /** @return list<array> newest first */
    public function recent(int $limit = 10): array
    {
        $s = $this->db->prepare('SELECT * FROM import_log ORDER BY id DESC LIMIT ?');
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->execute();
        return array_map(static fn ($r) => ['summary' => json_decode((string) $r['summary'], true)] + $r, $s->fetchAll());
    }

    /** The newest call with one of these statuses. */
    public function last(array $statuses): ?array
    {
        $place = implode(',', array_fill(0, count($statuses), '?'));
        $s = $this->db->prepare("SELECT * FROM import_log WHERE status IN ($place) ORDER BY id DESC LIMIT 1");
        $s->execute(array_values($statuses));
        $r = $s->fetch();
        return $r ? ['summary' => json_decode((string) $r['summary'], true)] + $r : null;
    }
}
