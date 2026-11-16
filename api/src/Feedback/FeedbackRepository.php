<?php

declare(strict_types=1);

namespace MusicRadar\Feedback;

use DomainException;
use PDO;

/**
 * Your feedback per release, stored by the release's stable MusicBrainz id.
 *
 * Rules (the same as in the frontend):
 *   - a rating and "Not interested" exclude each other
 *   - "Not interested" also removes the bookmark; saving removes "Not interested"
 *   - a row with nothing left is deleted (its history stays in the log)
 * Each row keeps a snapshot of the release, so it stays visible in My Music
 * and keeps teaching the engine after it has left the feed. Every change is
 * appended to release_feedback_log, so lost ratings can be restored.
 */
final class FeedbackRepository
{
    /** Until there is login, all feedback belongs to user 1. */
    public const DEFAULT_USER = 1;

    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = self::DEFAULT_USER,
    ) {
    }

    /** @return list<array{releaseId: string, rating: ?int, notInterested: bool, saved: bool, updatedAt: string, release: array}> */
    public function all(): array
    {
        $s = $this->db->prepare('SELECT * FROM release_feedback WHERE user_id = ? ORDER BY updated_at DESC, release_id');
        $s->execute([$this->userId]);
        return array_map(self::item(...), $s->fetchAll());
    }

    public function get(string $releaseId): ?array
    {
        $row = $this->row($releaseId, false);
        return $row ? self::item($row) : null;
    }

    /**
     * @param array|null $snapshot the release from the current feed; required
     *                             the first time a release gets feedback
     * @throws DomainException when the release is unknown (not in the feed, no earlier feedback)
     */
    public function apply(string $releaseId, FeedbackChange $change, ?array $snapshot, ?string $now = null): array
    {
        $now ??= gmdate('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $row = $this->row($releaseId, true);
            if ($row === null && $snapshot === null) {
                throw new DomainException('Unknown release');
            }
            $old = [
                'rating' => $row !== null && $row['rating'] !== null ? (int) $row['rating'] : null,
                'not_interested' => (bool) ($row['not_interested'] ?? false),
                'saved' => (bool) ($row['saved'] ?? false),
            ];
            $new = self::applyRules($old, $change);

            foreach ($new as $field => $value) {
                if ($value !== $old[$field]) {
                    $this->db->prepare('INSERT INTO release_feedback_log (user_id, release_id, field, old_value, new_value, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$this->userId, $releaseId, $field, self::logValue($old[$field]), self::logValue($value), $now]);
                }
            }

            $snapshotJson = json_encode($snapshot ?? json_decode($row['release_snapshot'], true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if ($new['rating'] === null && !$new['not_interested'] && !$new['saved']) {
                $this->db->prepare('DELETE FROM release_feedback WHERE user_id = ? AND release_id = ?')->execute([$this->userId, $releaseId]);
            } elseif ($row === null) {
                $this->db->prepare('INSERT INTO release_feedback (user_id, release_id, rating, not_interested, saved, release_snapshot, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$this->userId, $releaseId, $new['rating'], (int) $new['not_interested'], (int) $new['saved'], $snapshotJson, $now, $now]);
            } else {
                $this->db->prepare('UPDATE release_feedback SET rating = ?, not_interested = ?, saved = ?, release_snapshot = ?, updated_at = ? WHERE user_id = ? AND release_id = ?')
                    ->execute([$new['rating'], (int) $new['not_interested'], (int) $new['saved'], $snapshotJson, $now, $this->userId, $releaseId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->get($releaseId) ?? [
            'releaseId' => $releaseId, 'rating' => null, 'notInterested' => false, 'saved' => false,
            'updatedAt' => self::iso($now), 'release' => json_decode($snapshotJson, true),
        ];
    }

    /** A number that grows with every feedback change (rating, Not interested, bookmark). */
    public function version(): int
    {
        $s = $this->db->prepare('SELECT COALESCE(MAX(id), 0) FROM release_feedback_log WHERE user_id = ?');
        $s->execute([$this->userId]);
        return (int) $s->fetchColumn();
    }

    /** @return list<array{field: string, old: ?string, new: ?string, at: string}> */
    public function history(string $releaseId): array
    {
        $s = $this->db->prepare('SELECT field, old_value, new_value, created_at FROM release_feedback_log WHERE user_id = ? AND release_id = ? ORDER BY id');
        $s->execute([$this->userId, $releaseId]);
        return array_map(static fn ($r) => ['field' => $r['field'], 'old' => $r['old_value'], 'new' => $r['new_value'], 'at' => self::iso($r['created_at'])], $s->fetchAll());
    }

    /** @param array{rating: ?int, not_interested: bool, saved: bool} $s */
    private static function applyRules(array $s, FeedbackChange $c): array
    {
        switch ($c->field) {
            case 'rating':
                $s['rating'] = $c->value;
                if ($c->value !== null) {
                    $s['not_interested'] = false;
                }
                break;
            case 'notInterested':
                $s['not_interested'] = $c->value;
                if ($c->value) {
                    $s['rating'] = null;
                    $s['saved'] = false;
                }
                break;
            case 'saved':
                $s['saved'] = $c->value;
                if ($c->value) {
                    $s['not_interested'] = false;
                }
                break;
        }
        return $s;
    }

    private function row(string $releaseId, bool $lock): ?array
    {
        $s = $this->db->prepare('SELECT * FROM release_feedback WHERE user_id = ? AND release_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $s->execute([$this->userId, $releaseId]);
        return $s->fetch() ?: null;
    }

    private static function item(array $row): array
    {
        return [
            'releaseId' => $row['release_id'],
            'rating' => $row['rating'] !== null ? (int) $row['rating'] : null,
            'notInterested' => (bool) $row['not_interested'],
            'saved' => (bool) $row['saved'],
            'updatedAt' => self::iso($row['updated_at']),
            'release' => json_decode($row['release_snapshot'], true),
        ];
    }

    private static function logValue(int|bool|null $v): ?string
    {
        return $v === null ? null : (is_bool($v) ? ($v ? '1' : '0') : (string) $v);
    }

    private static function iso(string $utc): string
    {
        return str_replace(' ', 'T', $utc) . 'Z';
    }
}
