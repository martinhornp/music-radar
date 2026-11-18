<?php

declare(strict_types=1);

namespace MusicRadar\Links;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/** The optional link cache and its queue never write music, scores or feedback. */
final class TidalLinkStore
{
    /** Missing links are checked again this many days after the first check, then no more. */
    private const MISSING_RETRY_DAYS = [3, 29];
    /** A lookup error (or incomplete answer) is retried after this many days, at most ERROR_MAX_ATTEMPTS checks in all. */
    public const ERROR_RETRY_DAYS = 2;
    private const ERROR_MAX_ATTEMPTS = 3;

    /**
     * Whether a date backfill also retries releases it has checked before
     * (missing, ambiguous, errors). Off while backfilling: a backfill only
     * reaches releases never checked, and is done when there are none left.
     * The daily import retries as usual either way. Set to true once every
     * backfill is through.
     */
    public const BACKFILL_RETRIES = false;

    public function __construct(private readonly PDO $db)
    {
    }

    /** Import only; public reads never create or migrate tables. */
    public function install(): void
    {
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $opts = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->db->exec('CREATE TABLE IF NOT EXISTS tidal_links (
            release_id CHAR(36) PRIMARY KEY, release_hash CHAR(40) NOT NULL,
            status VARCHAR(16) NOT NULL, album_id VARCHAR(32) NULL, edition_id CHAR(36) NULL, approval_hash CHAR(64) NULL,
            checked_at VARCHAR(25) NOT NULL, next_check_at VARCHAR(25) NOT NULL, attempts INTEGER NOT NULL, daily_checks INTEGER NOT NULL,
            identity_hash CHAR(64) NULL, approved_identity CHAR(64) NULL, verified_at VARCHAR(25) NULL
        )' . $opts);
        $columns = $mysql
            ? $this->db->query('SHOW COLUMNS FROM tidal_links')->fetchAll(PDO::FETCH_COLUMN)
            : array_column($this->db->query('PRAGMA table_info(tidal_links)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        foreach (['identity_hash' => 'CHAR(64)', 'approved_identity' => 'CHAR(64)', 'verified_at' => 'VARCHAR(25)', 'last_error' => 'VARCHAR(32)'] as $name => $type) {
            if (!in_array($name, $columns, true)) {
                $this->db->exec("ALTER TABLE tidal_links ADD COLUMN $name $type NULL");
            }
        }
        if ($mysql) {
            $collation = $this->db->query("SELECT TABLE_COLLATION FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tidal_links'")->fetchColumn();
            if ($collation !== 'utf8mb4_unicode_ci') {
                $this->db->exec('ALTER TABLE tidal_links CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }
        }
        $this->db->exec('CREATE TABLE IF NOT EXISTS tidal_queue (
            release_id CHAR(36) PRIMARY KEY, priority INTEGER NOT NULL, queued_at VARCHAR(25) NOT NULL,
            first_checked_at VARCHAR(25) NULL, seed_run_id BIGINT NULL
        )' . $opts);
        $this->db->exec('CREATE TABLE IF NOT EXISTS tidal_state (
            id INTEGER PRIMARY KEY, started_at VARCHAR(25) NOT NULL, seed_run_id BIGINT NULL,
            seed_from VARCHAR(10) NULL, seed_to VARCHAR(10) NULL
        )' . $opts);
        foreach (['tidal_queue' => ['reserved_until' => 'VARCHAR(25)', 'backfill_run_id' => 'BIGINT', 'requested_from' => 'VARCHAR(10)'],
            'tidal_state' => ['paused_until' => 'VARCHAR(25)', 'service_failures' => 'INTEGER', 'backfill_run_id' => 'BIGINT']] as $table => $additions) {
            $columns = $mysql ? $this->db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN)
                : array_column($this->db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            foreach ($additions as $name => $type) {
                if (!in_array($name, $columns, true)) {
                    $this->db->exec("ALTER TABLE $table ADD COLUMN $name $type NULL");
                }
            }
        }
        // Older verified rows have no identity snapshot. Adopt only unchanged
        // source rows; candidates and conflicting source data are never approved.
        $rows = $this->db->query("SELECT r.*, l.checked_at FROM tidal_links l JOIN releases r ON r.id=l.release_id
            WHERE l.status='verified' AND l.approved_identity IS NULL AND l.release_hash=r.data_hash")->fetchAll(PDO::FETCH_ASSOC);
        $credits = $this->credits(array_column($rows, 'id'));
        $update = $this->db->prepare('UPDATE tidal_links SET identity_hash=?, approved_identity=?, verified_at=? WHERE release_id=?');
        foreach ($rows as $row) {
            $hash = TidalMatch::identityHash($row, $credits[$row['id']] ?? []);
            $update->execute([$hash, $hash, $row['checked_at'], $row['id']]);
        }
    }

    /** Register new discoveries and the active seven-day feed, without a historical backfill. */
    public function sync(string $now): void
    {
        $today = substr($now, 0, 10);
        $this->start($now);
        $state = $this->db->query('SELECT * FROM tidal_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $run = null;
        $recent = [];
        try {
            $run = $this->db->query("SELECT MAX(id) FROM recommendation_runs WHERE status='active'")->fetchColumn() ?: null;
            if ($run !== null) {
                $s = $this->db->prepare("SELECT r.id FROM recommendation_items i JOIN releases r ON r.id=i.release_id
                    WHERE i.run_id=? AND i.kind IN ('recommended','wildcard') AND r.type IN ('Album','EP')
                    AND r.first_release_date BETWEEN ? AND ?");
                $from = date('Y-m-d', strtotime("$today -6 days"));
                $s->execute([$run, $from, $today]);
                $recent = $s->fetchAll(PDO::FETCH_COLUMN);
                if ($state['seed_run_id'] === null) {
                    $this->db->beginTransaction();
                    try {
                        $this->db->prepare('UPDATE tidal_state SET seed_run_id=?, seed_from=?, seed_to=? WHERE id=1')->execute([$run, $from, $today]);
                        foreach ($recent as $id) {
                            $this->enqueue($id, 2, $now, (int) $run);
                        }
                        $this->db->commit();
                    } catch (Throwable $e) {
                        $this->db->rollBack();
                        throw $e;
                    }
                }
                if ($state['backfill_run_id'] === null) {
                    $this->backfillFeed((int) $run, $now);
                }
            }
        } catch (PDOException $e) {
            if (!$this->missingTable($e)) {
                throw $e;
            }
        }
        $known = $this->db->query('SELECT release_id, priority FROM tidal_queue')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($recent as $id) {
            if (!isset($known[$id]) || $known[$id] > 2) {
                $this->enqueue($id, 2, $now);
            }
        }
        $s = $this->db->prepare("SELECT r.id, r.first_seen_at FROM releases r LEFT JOIN tidal_queue q ON q.release_id=r.id
            WHERE r.type IN ('Album','EP') AND r.first_release_date BETWEEN ? AND ? AND r.first_seen_at >= ?
            AND (q.release_id IS NULL OR q.priority > 1)");
        $s->execute([date('Y-m-d', strtotime("$today -30 days")), $today, substr($state['started_at'], 0, 10)]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (strtotime($row['first_seen_at']) >= strtotime($state['started_at'])) {
                $this->enqueue($row['id'], 1, $now);
            }
        }
        $approvals = $this->db->query('SELECT r.*, l.approved_identity FROM tidal_links l JOIN releases r ON r.id=l.release_id
            LEFT JOIN tidal_queue q ON q.release_id=r.id WHERE l.approved_identity IS NOT NULL AND q.release_id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        $credits = $this->credits(array_column($approvals, 'id'));
        foreach ($approvals as $row) {
            if ($row['approved_identity'] !== TidalMatch::identityHash($row, $credits[$row['id']] ?? [])) {
                $this->enqueue($row['id'], 3, $now); // Concrete need, not a periodic historical sweep.
            }
        }
    }

    private function start(string $now): void
    {
        $insert = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $this->db->prepare("$insert INTO tidal_state (id, started_at) VALUES (1, ?)")
            ->execute([(new DateTimeImmutable($now))->setTime(0, 0)->format(DATE_ATOM)]);
    }

    public const RECHECK_STATUSES = ['error', 'ambiguous', 'missing'];

    /** @throws InvalidArgumentException */
    public static function validateRecheck(string $status, string $since, string $now): void
    {
        if (!in_array($status, self::RECHECK_STATUSES, true)) {
            throw new InvalidArgumentException('tidal_recheck must be error, ambiguous or missing');
        }
        $t = strtotime($since);
        if ($t === false || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $since) || $t > strtotime($now) + 300) {
            throw new InvalidArgumentException('tidal_since must be an ISO 8601 time, not in the future');
        }
    }

    public static function validateFrom(string $from, string $now): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if ($date === false || $date->format('Y-m-d') !== $from || $from > substr($now, 0, 10)) {
            throw new InvalidArgumentException('tidal_from must be a valid YYYY-MM-DD date no later than today');
        }
    }

    /** An explicit date stage adds only unresolved releases and never resets evidence or retry dates. */
    public function enqueueFrom(string $from, string $now): void
    {
        self::validateFrom($from, $now);
        $this->start($now);
        $rows = $this->stageRows($from, $now);
        $credits = $this->credits(array_column($rows, 'id'));
        $sql = 'INSERT INTO tidal_queue (release_id, priority, queued_at, first_checked_at, requested_from) VALUES (?, 4, ?, ?, ?)';
        $sql .= $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE first_checked_at=COALESCE(first_checked_at, VALUES(first_checked_at)), requested_from=COALESCE(requested_from, VALUES(requested_from))'
            : ' ON CONFLICT(release_id) DO UPDATE SET first_checked_at=COALESCE(first_checked_at, excluded.first_checked_at), requested_from=COALESCE(requested_from, excluded.requested_from)';
        $insert = $this->db->prepare($sql);
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                if ($this->approved($row, $credits[$row['id']] ?? [])) {
                    continue;
                }
                $known = in_array($row['tidal_status'], ['missing', 'ambiguous', 'error'], true);
                $changed = ($row['identity_hash'] !== null && $row['identity_hash'] !== TidalMatch::identityHash($row, $credits[$row['id']] ?? []))
                    || self::rulesChanged($row);
                $requested = !$known || $changed || $row['tidal_status'] === 'error'
                    || strtotime($row['next_check_at'] ?? $now) <= strtotime($now);
                if ($row['queued_id'] !== null && ($row['queue_checked_at'] !== null || !$known)
                    && ($row['queue_requested_from'] !== null || !$requested)) {
                    continue;
                }
                $insert->execute([$row['id'], self::utc($now), $known ? $row['tidal_checked_at'] : null, $requested ? $from : null]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function stageRows(string $from, string $now): array
    {
        $s = $this->db->prepare("SELECT r.*, l.identity_hash, l.approved_identity, l.album_id, l.edition_id, l.approval_hash,
            l.status AS tidal_status, l.release_hash, l.checked_at AS tidal_checked_at, l.next_check_at,
            q.release_id AS queued_id, q.first_checked_at AS queue_checked_at, q.requested_from AS queue_requested_from
            FROM releases r LEFT JOIN tidal_links l ON l.release_id=r.id
            LEFT JOIN tidal_queue q ON q.release_id=r.id
            WHERE r.type IN ('Album','EP') AND LENGTH(r.first_release_date)=10 AND r.first_release_date BETWEEN ? AND ?");
        $s->execute([$from, substr($now, 0, 10)]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** An "ambiguous" decided by earlier matching rules is checked again under the current ones. */
    /**
     * The daily import retries only releases scoring at least the Recommended
     * threshold (recommender.php recommended.min_fit); others still get their first check.
     */
    private static function minRetryScore(): float
    {
        static $min = null;
        return $min ??= (float) (require __DIR__ . '/../../config/recommender.php')['recommended']['min_fit'];
    }

    /** @return array<string, float> the active recommendations' score per release ([] before the first import) */
    private function activeScores(): array
    {
        try {
            return array_map('floatval', $this->db->query("SELECT s.release_id, s.score FROM recommendation_scores s
                JOIN recommendation_runs r ON r.id = s.run_id WHERE r.status = 'active'")->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (PDOException) {
            return []; // tables not installed yet: retry as before
        }
    }

    /**
     * Missing: checked again on day 3 and 29 after the first check, then
     * never again - TIDAL links rarely reach MusicBrainz within a day. Days until
     * the next check after the given attempt (1 = the first check).
     */
    private static function missingRetryDays(int $attempts): int
    {
        $days = self::MISSING_RETRY_DAYS;
        if ($attempts > count($days)) {
            return 3650; // schedule done (and the release is past the daily import's 30 days)
        }
        return $days[$attempts - 1] - ($days[$attempts - 2] ?? 0);
    }

    private static function rulesChanged(array $row): bool
    {
        return $row['tidal_status'] === 'ambiguous' && ($row['approval_hash'] ?? null) !== TidalMatch::validationHash();
    }

    private function approved(array $row, array $credits): bool
    {
        return $row['approved_identity'] !== null && $row['approved_identity'] === TidalMatch::identityHash($row, $credits)
            && !empty($row['edition_id']) && TidalMatch::albumId('https://tidal.com/album/' . $row['album_id']) !== null;
    }

    public function stageSummary(string $from, string $now): array
    {
        $rows = $this->stageRows($from, $now);
        $credits = $this->credits(array_column($rows, 'id'));
        $result = ['from' => $from, 'to' => substr($now, 0, 10), 'total' => count($rows), 'approved' => 0,
            'missing' => 0, 'ambiguous' => 0, 'error' => 0, 'unchecked' => 0, 'waiting_retry' => 0];
        foreach ($rows as $row) {
            if ($this->approved($row, $credits[$row['id']] ?? [])) {
                $result['approved']++;
                continue;
            }
            $changed = $row['identity_hash'] !== null && $row['identity_hash'] !== TidalMatch::identityHash($row, $credits[$row['id']] ?? []);
            $status = !$changed && in_array($row['tidal_status'], ['missing', 'ambiguous', 'error'], true) ? $row['tidal_status'] : 'unchecked';
            $result[$status]++;
            if ($status !== 'unchecked' && strtotime($row['next_check_at'] ?? $now) > strtotime($now)) {
                $result['waiting_retry']++;
            }
        }
        return $result;
    }

    /** One requested backfill of the active feed, never the historical catalog. */
    private function backfillFeed(int $run, string $now): void
    {
        $s = $this->db->prepare("SELECT r.id, CASE WHEN l.status IN ('verified','missing','ambiguous','error') THEN l.checked_at ELSE NULL END AS checked_at
            FROM recommendation_items i JOIN releases r ON r.id=i.release_id
            LEFT JOIN tidal_links l ON l.release_id=r.id WHERE i.run_id=? AND i.kind IN ('recommended','wildcard')
            AND r.type IN ('Album','EP') AND r.first_release_date<=?");
        $s->execute([$run, substr($now, 0, 10)]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $sql = 'INSERT INTO tidal_queue (release_id, priority, queued_at, first_checked_at, backfill_run_id) VALUES (?, 2, ?, ?, ?)';
        $sql .= $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE priority=LEAST(priority, VALUES(priority)), first_checked_at=COALESCE(first_checked_at, VALUES(first_checked_at)), backfill_run_id=VALUES(backfill_run_id)'
            : ' ON CONFLICT(release_id) DO UPDATE SET priority=MIN(priority, excluded.priority), first_checked_at=COALESCE(first_checked_at, excluded.first_checked_at), backfill_run_id=excluded.backfill_run_id';
        $insert = $this->db->prepare($sql);
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                // Existing cache results keep their next_check_at; no forced rechecks.
                $insert->execute([$row['id'], self::utc($now), $row['checked_at'], $run]);
            }
            $this->db->prepare('UPDATE tidal_state SET backfill_run_id=? WHERE id=1')->execute([$run]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function enqueue(string $id, int $priority, string $now, ?int $seed = null): void
    {
        $mysql = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = 'INSERT INTO tidal_queue (release_id, priority, queued_at, seed_run_id) VALUES (?, ?, ?, ?)';
        $sql .= $mysql
            ? ' ON DUPLICATE KEY UPDATE priority=LEAST(priority, VALUES(priority)), seed_run_id=COALESCE(seed_run_id, VALUES(seed_run_id))'
            : ' ON CONFLICT(release_id) DO UPDATE SET priority=MIN(priority, excluded.priority), seed_run_id=COALESCE(seed_run_id, excluded.seed_run_id)';
        $this->db->prepare($sql)->execute([$id, $priority, self::utc($now), $seed]);
    }

    /** Retries cannot outrank a discovery or an unprocessed recent feed entry. */
    /**
     * @param array{status: string, since: string}|null $recheck  a requested recheck of one status
     *        (error, ambiguous or missing) from $from on: every such release checked before `since`,
     *        regardless of its retry date - each once per run, since a check moves its checked_at
     */
    public function due(string $now, bool $includeReserved = false, ?string $from = null, ?array $recheck = null): ?array
    {
        return $this->dueRows($now, $includeReserved, $from, $recheck)[0] ?? null;
    }

    /**
     * What is left to check now, for the log: releases never checked and retries.
     *
     * @return array{new: int, retries: int}
     */
    public function dueCounts(string $now, ?string $from = null, ?array $recheck = null): array
    {
        $rows = $this->dueRows($now, true, $from, $recheck);
        $new = count(array_filter($rows, static fn ($r) => $r['tidal_first']));
        return ['new' => $new, 'retries' => count($rows) - $new];
    }

    /** Due releases, never checked first (newest first), then retries. */
    private function dueRows(string $now, bool $includeReserved, ?string $from, ?array $recheck): array
    {
        $today = substr($now, 0, 10);
        $rows = $this->db->query('SELECT r.*, q.priority, q.first_checked_at, q.seed_run_id, q.reserved_until, q.requested_from,
            l.identity_hash, l.approved_identity, l.approval_hash, l.status AS tidal_status, l.album_id, l.edition_id, l.verified_at,
            l.next_check_at, l.attempts, l.checked_at AS tidal_checked_at, l.daily_checks
            FROM tidal_queue q JOIN releases r ON r.id=q.release_id LEFT JOIN tidal_links l ON l.release_id=r.id')->fetchAll(PDO::FETCH_ASSOC);
        $credits = $this->credits(array_column($rows, 'id'));
        $scores = $from === null && $recheck === null ? $this->activeScores() : [];
        $due = [];
        foreach ($rows as $row) {
            // Approval ends automatic lookups, including legacy refresh retries.
            // Identity conflicts are still hidden by attach(), without another request.
            if ($row['approved_identity'] !== null) {
                continue;
            }
            if (!$includeReserved && $row['reserved_until'] !== null && strtotime($row['reserved_until']) > strtotime($now)) {
                continue;
            }
            if (!in_array($row['type'], ['Album', 'EP'], true) || $row['first_release_date'] > $today) {
                continue;
            }
            if ($from !== null && (strlen($row['first_release_date']) !== 10 || $row['first_release_date'] < $from
                || $this->approved($row, $credits[$row['id']] ?? []))) {
                continue;
            }
            $identity = TidalMatch::identityHash($row, $credits[$row['id']] ?? []);
            $changed = ($row['identity_hash'] !== null && $row['identity_hash'] !== $identity)
                || ($from !== null && $row['tidal_status'] === 'verified') || self::rulesChanged($row);
            $first = $row['first_checked_at'] === null;
            if ($recheck !== null) {
                if ($row['tidal_status'] === $recheck['status'] && $row['tidal_checked_at'] !== null
                    && strtotime($row['tidal_checked_at']) < strtotime($recheck['since'])) {
                    $due[] = $row + ['tidal_priority' => 3, 'tidal_first' => false, 'tidal_credits' => $credits[$row['id']] ?? []];
                }
                continue;
            }
            if ($from !== null && !self::BACKFILL_RETRIES && !$first) {
                continue; // See BACKFILL_RETRIES.
            }
            $recent = $row['first_release_date'] >= date('Y-m-d', strtotime("$today -30 days"));
            if (!$recent && $from === null) {
                continue; // The daily import never looks at releases over 30 days old (backfilled or not); the backfill workflow does.
            }
            if (!$first && $from === null && $scores !== [] && ($scores[$row['id']] ?? -1.0) < self::minRetryScore()) {
                continue; // A low score, or none (nothing to judge it on): no retries in the daily import, shown or not.
            }
            if ($first || $changed || $row['next_check_at'] === null || strtotime($row['next_check_at']) <= strtotime($now)) {
                $row['tidal_priority'] = $first ? (int) $row['priority'] : 3;
                $row['tidal_first'] = $first;
                $row['tidal_credits'] = $credits[$row['id']] ?? [];
                $due[] = $row;
            }
        }
        // A release never checked always goes before a retry, whatever queued it.
        usort($due, static fn ($a, $b) => ($b['tidal_first'] <=> $a['tidal_first']) ?: ($a['tidal_priority'] <=> $b['tidal_priority'])
            ?: strcmp($b['first_release_date'], $a['first_release_date']) ?: strcmp($a['id'], $b['id']));
        return $due;
    }

    public function save(array $release, array $match, string $now): void
    {
        $now = self::utc($now);
        $s = $this->db->prepare('SELECT * FROM tidal_links WHERE release_id=?');
        $s->execute([$release['id']]);
        $old = $s->fetch(PDO::FETCH_ASSOC) ?: [];
        $credits = $this->credits([$release['id']]);
        $identity = TidalMatch::identityHash($release, $credits[$release['id']] ?? []);
        $attempts = (int) ($old['attempts'] ?? 0) + 1;
        $daily = substr($old['checked_at'] ?? '', 0, 10) === substr($now, 0, 10) ? (int) ($old['daily_checks'] ?? 0) + 1 : 1;
        $failed = $match['status'] === 'error' || ($match['reason'] ?? '') === 'incomplete';
        $days = $failed ? self::ERROR_RETRY_DAYS
            : ($match['status'] === 'missing' ? self::missingRetryDays($attempts) : 7);
        if ($failed && $attempts >= self::ERROR_MAX_ATTEMPTS) {
            $days = 3650; // the first check and two retries failed: no more automatic lookups
            unset($match['retry_after']);
        }
        $approved = $old['approved_identity'] ?? null;
        $album = $old['album_id'] ?? null;
        $edition = $old['edition_id'] ?? null;
        $verified = $old['verified_at'] ?? null;
        if ($match['status'] === 'verified') {
            [$approved, $album, $edition, $verified] = [$identity, $match['album'], $match['edition'], $now];
        } elseif ($match['status'] === 'ambiguous' && ($match['reason'] ?? '') !== 'incomplete') {
            [$approved, $album, $edition, $verified] = [null, null, null, null];
        }
        $values = [$release['id'], $release['data_hash'], $match['status'], $album, $edition,
            $now, gmdate('Y-m-d\TH:i:s\Z', strtotime($now) + ($match['retry_after'] ?? $days * 86_400)), $attempts, $daily,
            TidalMatch::validationHash(), $identity, $approved, $verified, $match['error'] ?? null];
        $cols = ['release_id', 'release_hash', 'status', 'album_id', 'edition_id', 'checked_at', 'next_check_at',
            'attempts', 'daily_checks', 'approval_hash', 'identity_hash', 'approved_identity', 'verified_at', 'last_error'];
        $sql = 'INSERT INTO tidal_links (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $sets = array_slice($cols, 1);
        $sql .= $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c=VALUES($c)", $sets))
            : ' ON CONFLICT(release_id) DO UPDATE SET ' . implode(', ', array_map(static fn ($c) => "$c=excluded.$c", $sets));
        // Cache result and queue progress are one checkpoint; never hold this transaction during HTTP.
        $this->db->beginTransaction();
        try {
            $this->db->prepare($sql)->execute($values);
            $retry = $match['status'] === 'error' || ($match['reason'] ?? '') === 'incomplete';
            $this->db->prepare('UPDATE tidal_queue SET first_checked_at=COALESCE(first_checked_at, ?), reserved_until=NULL,
                requested_from=CASE WHEN ? THEN requested_from ELSE NULL END WHERE release_id=?')
                ->execute([$now, (int) $retry, $release['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** A killed request temporarily yields this item to the rest of the queue. */
    public function reserve(string $id, string $now): void
    {
        $this->db->prepare('UPDATE tidal_queue SET reserved_until=? WHERE release_id=?')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', strtotime($now) + 120), $id]);
    }

    public function pause(string $now, int $retryAfter): int
    {
        $failures = (int) $this->db->query('SELECT service_failures FROM tidal_state WHERE id=1')->fetchColumn();
        $seconds = max($retryAfter, min(900, 30 * (2 ** min(5, $failures))));
        $this->db->prepare('UPDATE tidal_state SET paused_until=?, service_failures=? WHERE id=1')
            ->execute([gmdate('Y-m-d\TH:i:s\Z', strtotime($now) + $seconds), $failures + 1]);
        return $seconds;
    }

    public function pauseRemaining(string $now): int
    {
        $until = $this->db->query('SELECT paused_until FROM tidal_state WHERE id=1')->fetchColumn();
        return $until ? max(0, strtotime($until) - strtotime($now)) : 0;
    }

    public function serviceRecovered(): void
    {
        $this->db->exec('UPDATE tidal_state SET paused_until=NULL, service_failures=0 WHERE id=1');
    }

    public function credits(array $ids): array
    {
        $result = [];
        foreach (array_chunk(array_values(array_unique($ids)), 200) as $chunk) {
            $s = $this->db->prepare('SELECT release_id, artist_mbid AS mbid FROM release_artists WHERE release_id IN ('
                . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY release_id, position');
            $s->execute($chunk);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $credit) {
                $result[$credit['release_id']][] = ['mbid' => $credit['mbid']];
            }
        }
        return $result;
    }

    /** Display approval is independent of retry dates, generic hashes and last-call failures. */
    public function attach(array $items, ?string $now = null): array
    {
        $links = [];
        if ($items !== []) {
            try {
                $ids = array_values(array_unique(array_column($items, 'id')));
                try {
                    $s = $this->db->prepare('SELECT r.*, l.status AS tidal_status, l.album_id, l.edition_id,
                        l.release_hash, l.approved_identity FROM tidal_links l JOIN releases r ON r.id=l.release_id
                        WHERE l.release_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                    $s->execute($ids);
                } catch (PDOException $e) {
                    if (!in_array((string) $e->getCode(), ['42S22', 'HY000'], true) || !str_contains($e->getMessage(), 'approved_identity')) {
                        throw $e;
                    }
                    // During rollout, unchanged legacy verified links stay visible before the first import migrates them.
                    $s = $this->db->prepare('SELECT r.*, l.status AS tidal_status, l.album_id, l.edition_id,
                        l.release_hash, NULL AS approved_identity FROM tidal_links l JOIN releases r ON r.id=l.release_id
                        WHERE l.release_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                    $s->execute($ids);
                }
                $rows = $s->fetchAll(PDO::FETCH_ASSOC);
                $credits = $this->credits($ids);
                foreach ($rows as $row) {
                    $approved = $row['approved_identity'];
                    $legacy = $approved === null && $row['tidal_status'] === 'verified' && $row['release_hash'] === $row['data_hash'];
                    if (($legacy || $approved === TidalMatch::identityHash($row, $credits[$row['id']] ?? []))
                        && !empty($row['edition_id']) && TidalMatch::albumId('https://tidal.com/album/' . $row['album_id']) !== null) {
                        $links[$row['id']] = $row['album_id'];
                    }
                }
            } catch (PDOException $e) {
                if (!$this->missingTable($e)) {
                    throw $e;
                }
            }
        }
        foreach ($items as &$item) {
            $item['tidalAlbumId'] = $links[$item['id']] ?? null;
        }
        return $items;
    }

    public function summary(bool $backfill = false): array
    {
        $state = $this->db->query('SELECT * FROM tidal_state WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: null;
        $column = $backfill ? 'backfill_run_id' : 'seed_run_id';
        $result = ['run_id' => $state[$column] ?? null, 'from' => $backfill ? null : ($state['seed_from'] ?? null),
            'to' => $backfill ? null : ($state['seed_to'] ?? null), 'total' => 0, 'checked' => 0, 'verified' => 0, 'missing' => 0, 'ambiguous' => 0, 'error' => 0, 'pending' => 0];
        if ($state === null || $state[$column] === null) {
            return $result;
        }
        $s = $this->db->prepare("SELECT q.first_checked_at, l.status FROM tidal_queue q LEFT JOIN tidal_links l ON l.release_id=q.release_id WHERE q.$column=?");
        $s->execute([$state[$column]]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result['total']++;
            if ($row['first_checked_at'] === null) {
                $result['pending']++;
            } else {
                $result['checked']++;
                $result[$row['status']]++;
            }
        }
        return $result;
    }

    private static function utc(string $now): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($now));
    }

    private function missingTable(PDOException $e): bool
    {
        return $e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table:');
    }
}
