<?php

declare(strict_types=1);

namespace MusicRadar\Feed;

use PDO;
use RuntimeException;

/**
 * Versioned recommendation runs in the database.
 *
 * A new run is written completely while its status is "building" - the site
 * keeps showing the active run meanwhile. Only when every item is stored is
 * it switched to "active" in one transaction (the previous active run
 * becomes "superseded"). A failed build is marked "failed" and never shown.
 * The last few runs are kept, so an earlier working feed can be restored.
 *
 *   recommendation_runs   one row per calculation: status, times, counts, header
 *   recommendation_items  the feed entries of a run (API contract, JSON)
 *   recommendation_scores the run's score for every release it assessed (also
 *                         those it did not pick), so My Music can show the
 *                         current score of a rated release
 */
final class RecommendationStore
{
    public const KEEP_RUNS = 7;

    private string $driver;

    public function __construct(private readonly PDO $db)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Creates the tables if missing (import and migration call this; the
     * read-only API does not). Only CREATE ... IF NOT EXISTS.
     */
    public function install(): void
    {
        $mysql = $this->driver === 'mysql';
        $long = $mysql ? 'MEDIUMTEXT' : 'TEXT';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $opts = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        foreach ([
            "CREATE TABLE IF NOT EXISTS recommendation_runs (
                id $id, status VARCHAR(16) NOT NULL, source VARCHAR(32) NOT NULL,
                started_at VARCHAR(25) NOT NULL, finished_at VARCHAR(25) NULL,
                generated_at VARCHAR(25) NULL, window_from VARCHAR(10) NULL, window_to VARCHAR(10) NULL,
                examined INTEGER NULL, recommended INTEGER NULL, wildcards INTEGER NULL,
                taste $long NULL, stats $long NULL, error TEXT NULL
            )$opts",
            "CREATE INDEX IF NOT EXISTS recommendation_runs_status ON recommendation_runs (status, id)",
            "CREATE TABLE IF NOT EXISTS recommendation_items (
                run_id BIGINT NOT NULL, position INTEGER NOT NULL, release_id CHAR(36) NOT NULL,
                kind VARCHAR(16) NOT NULL, item $long NOT NULL,
                PRIMARY KEY (run_id, position)
            )$opts",
            "CREATE INDEX IF NOT EXISTS recommendation_items_release ON recommendation_items (run_id, release_id)",
            "CREATE TABLE IF NOT EXISTS recommendation_scores (
                run_id BIGINT NOT NULL, release_id CHAR(36) NOT NULL, score DOUBLE NOT NULL, parts TEXT NOT NULL,
                PRIMARY KEY (run_id, release_id)
            )$opts",
        ] as $sql) {
            $this->db->exec($sql);
        }
    }

    /**
     * Stores a complete feed as a new run and activates it atomically.
     * Returns the run id. Throws (and marks the run failed) if anything goes
     * wrong - the active run is then unchanged.
     *
     * @param array<string, array{score: float, scoreParts: array<string, float>}> $scores
     *        the run's score per assessed release (see FeedBuilder::scores())
     */
    public function publish(array $feed, string $source, array $stats, string $now, array $scores = []): int
    {
        foreach ($feed['releases'] ?? [] as $item) {
            if (!is_string($item['id'] ?? null) || !preg_match('/^[0-9a-f-]{36}$/', $item['id'])
                || !in_array($item['pick']['kind'] ?? null, ['recommended', 'wildcard'], true)) {
                throw new \InvalidArgumentException('Malformed feed item - nothing published');
            }
        }
        $this->db->prepare('INSERT INTO recommendation_runs (status, source, started_at) VALUES (?, ?, ?)')
            ->execute(['building', $source, $now]);
        $runId = (int) $this->db->lastInsertId();
        try {
            $items = $feed['releases'] ?? throw new RuntimeException('feed without releases');
            foreach (array_chunk($items, 200, true) as $chunk) {
                $this->db->beginTransaction();
                $insert = $this->db->prepare('INSERT INTO recommendation_items (run_id, position, release_id, kind, item) VALUES (?, ?, ?, ?, ?)');
                foreach ($chunk as $pos => $item) {
                    $insert->execute([$runId, $pos, $item['id'], $item['pick']['kind'], json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
                }
                $this->db->commit();
            }
            foreach (array_chunk($scores, 500, true) as $chunk) {
                $this->db->beginTransaction();
                $insert = $this->db->prepare('INSERT INTO recommendation_scores (run_id, release_id, score, parts) VALUES (?, ?, ?, ?)');
                foreach ($chunk as $id => $s) {
                    $insert->execute([$runId, $id, $s['score'], json_encode($s['scoreParts'], JSON_THROW_ON_ERROR)]);
                }
                $this->db->commit();
            }
            $kinds = array_count_values(array_column(array_column($items, 'pick'), 'kind'));

            // Switch over in one transaction, and only if every item is there.
            $this->db->beginTransaction();
            $stored = $this->db->prepare('SELECT COUNT(*) FROM recommendation_items WHERE run_id = ?');
            $stored->execute([$runId]);
            if ((int) $stored->fetchColumn() !== count($items)) {
                throw new RuntimeException('not all items were stored');
            }
            $this->db->prepare("UPDATE recommendation_runs SET status = 'superseded' WHERE status = 'active'")->execute();
            $this->db->prepare("UPDATE recommendation_runs SET status = 'active', finished_at = ?, generated_at = ?, window_from = ?, window_to = ?,
                examined = ?, recommended = ?, wildcards = ?, taste = ?, stats = ? WHERE id = ?")
                ->execute([gmdate('Y-m-d\TH:i:s\Z'), $feed['generatedAt'], $feed['window'][0] ?? null, $feed['window'][1] ?? null,
                    (int) ($feed['examined'] ?? 0), $kinds['recommended'] ?? 0, $kinds['wildcard'] ?? 0,
                    json_encode($feed['taste'] ?? [], JSON_UNESCAPED_UNICODE), json_encode($stats, JSON_UNESCAPED_UNICODE), $runId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->prepare("UPDATE recommendation_runs SET status = 'failed', finished_at = ?, error = ? WHERE id = ?")
                ->execute([gmdate('Y-m-d\TH:i:s\Z'), mb_substr($e->getMessage(), 0, 500), $runId]);
            throw $e;
        }
        $this->prune();
        return $runId;
    }

    /** The active run's feed in the API contract, or null if there is none. */
    public function activeFeed(): ?array
    {
        $run = $this->activeRun();
        if ($run === null) {
            return null;
        }
        $s = $this->db->prepare('SELECT item FROM recommendation_items WHERE run_id = ? ORDER BY position');
        $s->execute([$run['id']]);
        return [
            'generatedAt' => $run['generated_at'],
            'window' => [$run['window_from'], $run['window_to']],
            'examined' => (int) $run['examined'],
            'taste' => json_decode((string) $run['taste'], true) ?? ['genres' => [], 'artists' => []],
            'releases' => array_map(static fn ($json) => json_decode($json, true), $s->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    /** One release of the active run (for feedback snapshots), or null. */
    public function activeItem(string $releaseId): ?array
    {
        $run = $this->activeRun();
        if ($run === null) {
            return null;
        }
        $s = $this->db->prepare('SELECT item FROM recommendation_items WHERE run_id = ? AND release_id = ? LIMIT 1');
        $s->execute([$run['id'], $releaseId]);
        $json = $s->fetchColumn();
        return $json === false ? null : json_decode($json, true);
    }

    /**
     * Several releases of the active run at once (for search results).
     *
     * @param list<string> $releaseIds
     * @return array<string, array> feed entry by release id (only those in the run)
     */
    public function activeItems(array $releaseIds): array
    {
        $run = $this->activeRun();
        if ($run === null || $releaseIds === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($releaseIds), '?'));
        $s = $this->db->prepare("SELECT release_id, item FROM recommendation_items WHERE run_id = ? AND release_id IN ($place)");
        $s->execute([$run['id'], ...array_values($releaseIds)]);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_NUM) as [$id, $json]) {
            $out[$id] = json_decode($json, true);
        }
        return $out;
    }

    /**
     * The active run's score for these releases - picked or not - or nothing
     * for a release it did not assess (outside its window, or too little data).
     *
     * @param list<string> $releaseIds
     * @return array<string, array{score: float, scoreParts: array<string, float>}>
     */
    public function activeScores(array $releaseIds): array
    {
        $run = $this->activeRun();
        if ($run === null || $releaseIds === []) {
            return [];
        }
        $place = implode(',', array_fill(0, count($releaseIds), '?'));
        try {
            $s = $this->db->prepare("SELECT release_id, score, parts FROM recommendation_scores WHERE run_id = ? AND release_id IN ($place)");
            $s->execute([$run['id'], ...array_values($releaseIds)]);
        } catch (\PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1146, 1], true) && str_contains($e->getMessage(), 'recommendation_scores')) {
                return []; // not installed yet (before the first import with it)
            }
            throw $e;
        }
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_NUM) as [$id, $score, $parts]) {
            $out[$id] = ['score' => (float) $score, 'scoreParts' => array_map('floatval', json_decode($parts, true) ?? [])];
        }
        return $out;
    }

    public function activeRun(): ?array
    {
        try {
            $row = $this->db->query("SELECT * FROM recommendation_runs WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetch();
        } catch (\PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1146, 1], true) && str_contains($e->getMessage(), 'recommendation_runs')) {
                return null; // not installed yet (before the first import)
            }
            throw $e;
        }
        return $row ?: null;
    }

    /** @return list<array> newest first, without the large columns */
    public function runs(int $limit = 10): array
    {
        $s = $this->db->prepare('SELECT id, status, source, started_at, finished_at, generated_at, window_from, window_to, examined, recommended, wildcards, error
            FROM recommendation_runs ORDER BY id DESC LIMIT ?');
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /** Makes an earlier successful run the active one again (manual rollback). */
    public function activate(int $runId): void
    {
        $this->db->beginTransaction();
        try {
            $s = $this->db->prepare("SELECT COUNT(*) FROM recommendation_runs WHERE id = ? AND status IN ('active', 'superseded')");
            $s->execute([$runId]);
            if ((int) $s->fetchColumn() !== 1) {
                throw new RuntimeException("Run $runId cannot be activated");
            }
            $this->db->exec("UPDATE recommendation_runs SET status = 'superseded' WHERE status = 'active'");
            $this->db->prepare("UPDATE recommendation_runs SET status = 'active' WHERE id = ?")->execute([$runId]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Keeps the newest KEEP_RUNS runs (always including the active one). */
    private function prune(): void
    {
        $keep = $this->db->query('SELECT id FROM recommendation_runs ORDER BY id DESC LIMIT ' . self::KEEP_RUNS)->fetchAll(PDO::FETCH_COLUMN);
        $active = $this->activeRun();
        $keep = array_map('intval', [...$keep, ...($active ? [$active['id']] : [])]);
        $place = implode(',', array_fill(0, count($keep), '?'));
        $this->db->prepare("DELETE FROM recommendation_items WHERE run_id NOT IN ($place)")->execute($keep);
        $this->db->prepare("DELETE FROM recommendation_scores WHERE run_id NOT IN ($place)")->execute($keep);
        $this->db->prepare("DELETE FROM recommendation_runs WHERE id NOT IN ($place)")->execute($keep);
    }
}
