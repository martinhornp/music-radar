<?php

declare(strict_types=1);

namespace MusicRadar\TopAlbums;

use InvalidArgumentException;
use MusicRadar\Discovery\CandidateFinder;
use MusicRadar\Feed\FeedBuilder;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\MusicBrainz\MusicBrainzException;
use MusicRadar\Search\ReleaseSearch;
use PDO;

/**
 * Your most played albums on Last.fm (all time), to rate on the "Bedøm albums"
 * page. Imported by hand (the "Import top albums" workflow, top 100 or more):
 * resolve() runs on the GitHub runner, addMissing() stores the albums that are
 * not in the list yet - a later run (e.g. top 200, or after a MusicBrainz
 * error) only adds, it never changes or removes an album. Each album is a MusicBrainz release group - the same id as
 * everywhere else - so a rating is ordinary feedback the engine learns from,
 * also for albums far outside the 31-day window (entry() gives its snapshot).
 */
final class TopAlbums
{
    public const LIMIT = 100;   // default number of albums to resolve
    public const MAX = 500;     // most albums one import may send

    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS top_albums (
          id CHAR(36) NOT NULL PRIMARY KEY COMMENT 'MusicBrainz release-group id',
          position SMALLINT UNSIGNED NOT NULL COMMENT 'rank among your most played albums',
          plays INT UNSIGNED NOT NULL COMMENT 'Last.fm plays, all time, at import',
          title VARCHAR(500) NOT NULL,
          type VARCHAR(8) NOT NULL,
          first_release_date VARCHAR(10) NOT NULL,
          artist_name VARCHAR(500) NOT NULL,
          artist_mbid CHAR(36) NULL,
          tags LONGTEXT NOT NULL COMMENT 'MusicBrainz genre tags, strongest first (JSON)',
          imported_at DATETIME NOT NULL,
          CONSTRAINT top_albums_tags CHECK (JSON_VALID(tags))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

    public function __construct(private readonly PDO $db)
    {
        $this->db->exec(self::SCHEMA);
    }

    public function count(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM top_albums')->fetchColumn();
    }

    /**
     * Adds the albums that are not in the list yet; albums already in it are
     * left exactly as they are (plays, position).
     *
     * @param list<array<string, mixed>> $albums as resolve() returns them, most played first
     * @throws InvalidArgumentException when an entry is invalid
     * @return int albums added
     */
    public function addMissing(array $albums, string $now): int
    {
        $clean = array_map(self::validate(...), $albums);
        if (count(array_unique(array_column($clean, 'id'))) !== count($clean) || count($clean) > self::MAX) {
            throw new InvalidArgumentException('Duplicate albums or more than ' . self::MAX);
        }
        $added = 0;
        $this->db->beginTransaction();
        try {
            $known = array_flip($this->db->query('SELECT id FROM top_albums FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));
            $insert = $this->db->prepare('INSERT INTO top_albums (id, position, plays, title, type, first_release_date, artist_name, artist_mbid, tags, imported_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($clean as $i => $a) {
                if (isset($known[$a['id']])) {
                    continue;
                }
                $insert->execute([$a['id'], $i + 1, $a['plays'], $a['title'], $a['type'], $a['first_release_date'], $a['artist_name'],
                    $a['artist_mbid'], json_encode($a['tags'], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s', strtotime($now))]);
                $added++;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $added;
    }

    /** @return list<array{plays: int, release: array<string, mixed>}> most played first */
    public function all(): array
    {
        $rows = $this->db->query('SELECT * FROM top_albums ORDER BY plays DESC, position')->fetchAll();
        return array_map(static fn ($row) => ['plays' => (int) $row['plays'], 'release' => self::toEntry($row)], $rows);
    }

    /** The album as a release (feed format), or null when it is not in the list. */
    public function entry(string $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM top_albums WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch();
        return $row ? self::toEntry($row) : null;
    }

    private static function toEntry(array $row): array
    {
        $tags = json_decode((string) $row['tags'], true) ?: [];
        $release = [
            'id' => $row['id'], 'title' => $row['title'], 'type' => $row['type'], 'first_release_date' => $row['first_release_date'],
            'artist' => ['name' => $row['artist_name'], 'mbid' => $row['artist_mbid']], 'genre_tags' => $tags, 'facts' => [['source' => 'musicbrainz']],
        ];
        $pick = ['type' => 'none', 'angle' => null, 'origin' => 'discovery', 'data_quality' => $tags !== [] ? 'good' : 'limited'];
        return FeedBuilder::entry($pick, 0, $release, ReleaseSearch::family($tags), []);
    }

    /** @return array{id: string, plays: int, title: string, type: string, first_release_date: string, artist_name: string, artist_mbid: ?string, tags: list<string>} */
    private static function validate(mixed $a): array
    {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        $text = static fn ($v) => is_string($v) && trim($v) !== '' && mb_strlen($v) <= 500;
        if (!is_array($a) || !is_string($a['id'] ?? null) || !preg_match($uuid, $a['id']) || !is_int($a['plays'] ?? null) || $a['plays'] < 0
            || !$text($a['title'] ?? null) || !$text($a['artist_name'] ?? null) || !in_array($a['type'] ?? null, ['Album', 'EP'], true)
            || !is_string($a['first_release_date'] ?? null) || !preg_match('/^(\d{4}(-\d{2}(-\d{2})?)?)?$/', $a['first_release_date'])
            || !(($a['artist_mbid'] ?? null) === null || (is_string($a['artist_mbid']) && preg_match($uuid, $a['artist_mbid'])))
            || !is_array($a['tags'] ?? null) || array_filter($a['tags'], static fn ($t) => !$text($t)) !== []) {
            throw new InvalidArgumentException('Invalid album entry');
        }
        return ['id' => $a['id'], 'plays' => $a['plays'], 'title' => trim($a['title']), 'type' => $a['type'], 'first_release_date' => $a['first_release_date'],
            'artist_name' => trim($a['artist_name']), 'artist_mbid' => $a['artist_mbid'] ?? null, 'tags' => array_values(array_slice($a['tags'], 0, 10))];
    }

    /**
     * Your most played albums as MusicBrainz albums/EPs (singles and albums
     * MusicBrainz does not know are skipped; the same album twice counts once),
     * most played first, at most $limit. Runs on the GitHub runner.
     *
     * @param callable(string): void $log
     * @return list<array<string, mixed>>
     */
    public static function resolve(LastFmClient $lastfm, MusicBrainzClient $mb, string $user, callable $log, int $limit = self::LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX));
        $out = [];
        // Room for the singles and unknown albums that are skipped.
        foreach ($lastfm->userTopAlbums($user, 'overall', min(1000, $limit * 3)) as $album) {
            if (count($out) >= $limit) {
                break;
            }
            // Last.fm often has the edition in the name ("Brainwashed (Deluxe)",
            // "Sneglzilla (Remastered)"): without a match, try again without it.
            $names = array_values(array_unique(array_filter([$album['name'], trim((string) preg_replace('/\s*[(\[][^()\[\]]*[)\]]\s*$/', '', $album['name']))])));
            $rg = null;
            foreach ($names as $name) {
                $q = sprintf('releasegroup:"%s" AND artist:"%s" AND (primarytype:album OR primarytype:ep)', self::quote($name), self::quote($album['artist']));
                try {
                    $hits = $mb->searchReleaseGroups($q, 5)['release-groups'];
                } catch (MusicBrainzException $e) {
                    $log("skipped (MusicBrainz error): {$album['artist']} - {$album['name']}: {$e->getMessage()}");
                    continue 2;
                }
                foreach ($hits as $h) {
                    $artistIds = array_map(static fn ($c) => (string) ($c['artist']['id'] ?? ''), $h['artist-credit'] ?? []);
                    if ((int) ($h['score'] ?? 0) >= 90 && in_array($h['primary-type'] ?? '', ['Album', 'EP'], true)
                        && !in_array(CandidateFinder::VARIOUS_ARTISTS, $artistIds, true)) {
                        $rg = $h;
                        break 2;
                    }
                }
            }
            if ($rg === null) {
                $log("skipped (no album/EP on MusicBrainz): {$album['artist']} - {$album['name']}");
                continue;
            }
            if (isset($out[$rg['id']])) {
                $out[$rg['id']]['plays'] += $album['playcount']; // two Last.fm spellings of one album
                continue;
            }
            $artist = CandidateFinder::artist($rg['artist-credit']);
            $tags = $rg['tags'] ?? [];
            usort($tags, static fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
            $out[$rg['id']] = [
                'id' => (string) $rg['id'], 'plays' => $album['playcount'], 'title' => (string) $rg['title'], 'type' => (string) $rg['primary-type'],
                'first_release_date' => (string) ($rg['first-release-date'] ?? ''), 'artist_name' => $artist['name'],
                'artist_mbid' => $artist['mbid'] !== '' ? $artist['mbid'] : null,
                'tags' => array_values(array_map(static fn ($t) => (string) $t['name'], $tags)),
            ];
            $log(sprintf('%d/%d found: %s - %s', count($out), $limit, $artist['name'], $rg['title']));
        }
        // Albums without their own tags: the artist's MusicBrainz tags (one request per 60 artists).
        $need = array_values(array_unique(array_filter(array_map(static fn ($a) => $a['tags'] === [] ? $a['artist_mbid'] : null, $out))));
        foreach (array_chunk($need, 60) as $ids) {
            try {
                $artists = $mb->artistsByIds($ids);
            } catch (MusicBrainzException $e) {
                $log('artist tags not fetched: ' . $e->getMessage());
                continue;
            }
            foreach ($out as &$a) {
                if ($a['tags'] === [] && isset($artists[$a['artist_mbid']])) {
                    $tags = $artists[$a['artist_mbid']]['tags'] ?? [];
                    usort($tags, static fn ($x, $y) => ($y['count'] ?? 0) <=> ($x['count'] ?? 0));
                    $a['tags'] = array_values(array_map(static fn ($t) => (string) $t['name'], $tags));
                }
            }
            unset($a);
        }
        $list = array_values($out);
        usort($list, static fn ($a, $b) => $b['plays'] <=> $a['plays']);
        return $list;
    }

    private static function quote(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }
}
