<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

use PDO;

/**
 * Persistent state for the discovery pipeline, so nothing is fetched twice:
 *   releases  every new album/EP seen (pool + known artists), its Last.fm
 *             album data and when it was last seen
 *   artists   MusicBrainz + Last.fm data per artist, with fetch times
 *   slices    which day/type of the pool was collected when
 *   failures  errors per item and stage (retried on later runs)
 *   meta      small key/value state (e.g. when known artists were checked)
 *   release_artists  every credited artist of a release, in credit order
 *             (collaborations: "A & B", "A feat. B", 3+ artists)
 *   listening_artists  the listener's Last.fm artist library (weekly), for
 *             the artist-interest signal
 *
 *   profiles  the music profile (Last.fm + MusicBrainz), newest = in use
 *
 * In production MariaDB on one.com (the central database); locally SQLite or
 * the Docker MariaDB. The SQL is portable; only the upsert syntax and column
 * types differ per driver.
 */
final class DiscoveryStore
{
    private PDO $db;
    private string $driver;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->migrate();
    }

    public static function sqlite(string $file): self
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return new self(new PDO('sqlite:' . $file));
    }

    private function migrate(): void
    {
        $mysql = $this->driver === 'mysql';
        $text = 'TEXT';
        $long = $mysql ? 'MEDIUMTEXT' : 'TEXT';
        $id = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $opts = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        foreach ([
            "CREATE TABLE IF NOT EXISTS releases (
                id CHAR(36) PRIMARY KEY, title $text, type VARCHAR(16), first_release_date VARCHAR(10),
                artist_mbid CHAR(36), artist_name $text, primary_name $text, credited INTEGER,
                release_tags $long, origin VARCHAR(16), watch_level VARCHAR(16),
                date_check $long, data_hash CHAR(40),
                first_seen_at VARCHAR(25), last_seen_at VARCHAR(25), changed_at VARCHAR(25),
                lastfm_album $long, lastfm_album_status VARCHAR(16), lastfm_album_at VARCHAR(25),
                prescore REAL, prescore_quality VARCHAR(16)
            )",
            "CREATE INDEX IF NOT EXISTS releases_date ON releases (first_release_date)",
            "CREATE INDEX IF NOT EXISTS releases_artist ON releases (artist_mbid)",
            "CREATE TABLE IF NOT EXISTS artists (
                mbid CHAR(36) PRIMARY KEY, name $text, mb $long, mb_status VARCHAR(16), mb_fetched_at VARCHAR(25),
                lastfm $long, lastfm_status VARCHAR(16), lastfm_fetched_at VARCHAR(25)
            )",
            "CREATE TABLE IF NOT EXISTS slices (
                day VARCHAR(10), type VARCHAR(8), collected_at VARCHAR(25), hits INTEGER, exact INTEGER,
                partitions INTEGER, unreachable INTEGER, PRIMARY KEY (day, type)
            )",
            "CREATE TABLE IF NOT EXISTS failures (
                stage VARCHAR(32), item VARCHAR(64), message $long, attempts INTEGER, last_at VARCHAR(25),
                PRIMARY KEY (stage, item)
            )",
            "CREATE TABLE IF NOT EXISTS meta (k VARCHAR(64) PRIMARY KEY, v $long)",
            "CREATE TABLE IF NOT EXISTS profiles (
                id $id, created_at VARCHAR(25), source VARCHAR(32), profile $long
            )",
            "CREATE TABLE IF NOT EXISTS release_artists (
                release_id CHAR(36), position INTEGER, artist_mbid CHAR(36), name $text, artist_name $text,
                joinphrase VARCHAR(64), PRIMARY KEY (release_id, position)
            )",
            "CREATE INDEX IF NOT EXISTS release_artists_artist ON release_artists (artist_mbid)",
            "CREATE TABLE IF NOT EXISTS listening_artists (
                id $id, name $text, name_key VARCHAR(191), lastfm_mbid CHAR(36), mbid CHAR(36), identity VARCHAR(16),
                plays_overall INTEGER, plays_12month INTEGER, plays_3month INTEGER, rank_overall INTEGER,
                source VARCHAR(32), updated_at VARCHAR(25)
            )",
            "CREATE INDEX IF NOT EXISTS listening_artists_key ON listening_artists (name_key)",
            // Last.fm Similar Artists per artist (key m:<mbid> or n:<name key>): how the
            // Last.fm page was identified, and the links that matter here - all of them
            // for your profile artists, only those to artists in your library otherwise.
            "CREATE TABLE IF NOT EXISTS artist_similar (
                artist_key VARCHAR(191) PRIMARY KEY, name $text, mbid CHAR(36), lastfm_page $text, identity VARCHAR(16),
                role VARCHAR(16), links $long, list_size INTEGER, fetched_at VARCHAR(25)
            )",
        ] as $sql) {
            // Only CREATE ... IF NOT EXISTS: existing tables and data are never changed.
            $this->db->exec(str_starts_with($sql, 'CREATE TABLE') ? $sql . $opts : $sql);
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    // --- copying between databases (SQLite -> MariaDB migration) --------------------

    /** Tables that can be copied, with their primary key and columns. */
    public const COPYABLE = [
        'releases' => [['id'], ['id', 'title', 'type', 'first_release_date', 'artist_mbid', 'artist_name', 'primary_name', 'credited',
            'release_tags', 'origin', 'watch_level', 'date_check', 'data_hash', 'first_seen_at', 'last_seen_at', 'changed_at',
            'lastfm_album', 'lastfm_album_status', 'lastfm_album_at', 'prescore', 'prescore_quality']],
        'artists' => [['mbid'], ['mbid', 'name', 'mb', 'mb_status', 'mb_fetched_at', 'lastfm', 'lastfm_status', 'lastfm_fetched_at']],
        'slices' => [['day', 'type'], ['day', 'type', 'collected_at', 'hits', 'exact', 'partitions', 'unreachable']],
        'failures' => [['stage', 'item'], ['stage', 'item', 'message', 'attempts', 'last_at']],
        'meta' => [['k'], ['k', 'v']],
    ];

    /** @return array<string, int> rows per copyable table (+ profiles) */
    public function counts(): array
    {
        $out = [];
        foreach ([...array_keys(self::COPYABLE), 'profiles'] as $t) {
            $out[$t] = (int) $this->db->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        }
        return $out;
    }

    /**
     * Per table: sha1 over every primary key (and, for releases, the data
     * hash) in key order - equal fingerprints mean the same rows on both sides.
     *
     * @return array<string, string>
     */
    public function fingerprints(): array
    {
        $out = [];
        foreach (self::COPYABLE as $table => [$keys]) {
            $cols = $table === 'releases' ? [...$keys, 'data_hash'] : $keys;
            $lines = [];
            foreach ($this->db->query('SELECT ' . implode(', ', $cols) . " FROM $table") as $row) {
                $lines[] = implode("\t", array_map('strval', $row));
            }
            // Sorted here, not in SQL: SQLite and MariaDB collations order differently.
            sort($lines, SORT_STRING);
            $out[$table] = sha1(implode("\n", $lines));
        }
        return $out;
    }

    /** @return list<array<string, mixed>> a page of a copyable table, in primary-key order */
    public function rows(string $table, int $offset, int $limit): array
    {
        [$keys] = self::COPYABLE[$table] ?? throw new \InvalidArgumentException("Not a copyable table: $table");
        $s = $this->db->prepare("SELECT * FROM $table ORDER BY " . implode(', ', $keys) . ' LIMIT ? OFFSET ?');
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->bindValue(2, $offset, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Inserts or replaces rows by primary key - running it twice gives the
     * same result (no duplicates). Only known columns are written.
     *
     * @return int rows written
     */
    public function importRows(string $table, array $rows): int
    {
        [$keys, $columns] = self::COPYABLE[$table] ?? throw new \InvalidArgumentException("Not a copyable table: $table");
        return $this->transaction(function () use ($table, $keys, $columns, $rows): int {
            foreach ($rows as $row) {
                if (!is_array($row) || array_diff($keys, array_keys($row)) !== []) {
                    throw new \InvalidArgumentException("Row without primary key for $table");
                }
                $this->upsert($table, $keys, array_intersect_key($row, array_flip($columns)) + array_fill_keys($columns, null));
            }
            return count($rows);
        });
    }

    // --- music profile -----------------------------------------------------------

    public function saveProfile(array $profile, string $source, string $now): int
    {
        $this->db->prepare('INSERT INTO profiles (created_at, source, profile) VALUES (?, ?, ?)')
            ->execute([$now, $source, json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        $id = (int) $this->db->lastInsertId();
        // Keep the last few versions only.
        $this->db->prepare('DELETE FROM profiles WHERE id <= ?')->execute([$id - 10]);
        return $id;
    }

    /** @return array{id: int, created_at: string, source: string, profile: array}|null the profile in use */
    public function latestProfile(): ?array
    {
        $row = $this->db->query('SELECT * FROM profiles ORDER BY id DESC LIMIT 1')->fetch();
        return $row ? ['id' => (int) $row['id'], 'created_at' => $row['created_at'], 'source' => $row['source'],
            'profile' => json_decode($row['profile'], true, flags: JSON_THROW_ON_ERROR)] : null;
    }

    /** INSERT or UPDATE by primary key - portable between SQLite and MariaDB. */
    private function upsert(string $table, array $keys, array $row): void
    {
        $cols = array_keys($row);
        $place = implode(', ', array_map(static fn ($c) => ":$c", $cols));
        $set = array_values(array_filter($cols, static fn ($c) => !in_array($c, $keys, true)));
        $sql = "INSERT INTO $table (" . implode(', ', $cols) . ") VALUES ($place) ";
        $sql .= $this->driver === 'mysql'
            ? 'ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn ($c) => "$c = VALUES($c)", $set))
            : 'ON CONFLICT (' . implode(', ', $keys) . ') DO UPDATE SET ' . implode(', ', array_map(static fn ($c) => "$c = excluded.$c", $set));
        $this->db->prepare($sql)->execute($row);
    }

    public function transaction(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try {
            $r = $fn();
            $this->db->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // --- releases -------------------------------------------------------------

    /**
     * Saves a release seen in the pool or for a known artist. Returns 'new',
     * 'changed' or 'unchanged' (title, type, date, artist credit and tags).
     *
     * Both stages store the release's own MusicBrainz credit, so a release no
     * longer flips between "A & B" and "B" from one stage to the next. A
     * stand-in without a credit ('credits' => null) never replaces a stored
     * credit. Last.fm album data is kept unless what it was looked up by (the
     * first artist and the title) changed.
     *
     * @param string|null $watchLevel  following | interested | null - from every credited artist
     */
    public function saveRelease(array $r, string $origin, ?string $watchLevel, string $now): string
    {
        $old = $this->release($r['id']);
        $oldCredits = $old === null ? null : ($this->credits([$r['id']])[$r['id']] ?? null);
        $artist = $r['artist'];
        $credits = $artist['credits'] ?? null;
        if ($credits === null && $old !== null) {
            $artist = ['name' => $old['artist_name'], 'mbid' => $old['artist_mbid'], 'primary_name' => $old['primary_name'], 'credited_artists' => (int) $old['credited']];
            $credits = $oldCredits;
        }
        $new = self::content($r['title'], $r['type'], $r['first_release_date'], $artist['name'], $artist['mbid'], $credits, $r['release_tags']);
        $status = 'new';
        if ($old !== null) {
            $before = self::content($old['title'], $old['type'], $old['first_release_date'], $old['artist_name'], $old['artist_mbid'],
                $oldCredits, json_decode((string) $old['release_tags'], true) ?: []);
            $compared = $new;
            // Rows from before credits were stored: compare without them.
            if ($oldCredits === null) {
                unset($before['credits'], $compared['credits']);
            }
            $status = $before === $compared ? 'unchanged' : 'changed';
        }
        $lookupChanged = $old !== null && ($old['title'] !== $r['title'] || $old['primary_name'] !== $artist['primary_name']);
        $originNow = $old !== null && $old['origin'] !== $origin ? 'both' : $origin;
        $this->upsert('releases', ['id'], [
            'id' => $r['id'],
            'title' => $r['title'],
            'type' => $r['type'],
            'first_release_date' => $r['first_release_date'],
            'artist_mbid' => $artist['mbid'],
            'artist_name' => $artist['name'],
            'primary_name' => $artist['primary_name'],
            'credited' => $credits !== null ? count($credits) : $artist['credited_artists'],
            'release_tags' => json_encode($r['release_tags']),
            'origin' => $originNow,
            'watch_level' => $watchLevel,
            'date_check' => isset($r['date_check']) ? json_encode($r['date_check']) : ($old['date_check'] ?? null),
            'data_hash' => sha1(json_encode($new)),
            'first_seen_at' => $old['first_seen_at'] ?? $now,
            'last_seen_at' => $now,
            'changed_at' => $status === 'unchanged' ? ($old['changed_at'] ?? $now) : $now,
            'lastfm_album' => $lookupChanged ? null : ($old['lastfm_album'] ?? null),
            'lastfm_album_status' => $lookupChanged ? null : ($old['lastfm_album_status'] ?? null),
            'lastfm_album_at' => $lookupChanged ? null : ($old['lastfm_album_at'] ?? null),
            'prescore' => $old['prescore'] ?? null,
            'prescore_quality' => $old['prescore_quality'] ?? null,
        ]);
        if ($credits !== null && $credits !== $oldCredits) {
            $this->saveCredits($r['id'], $credits);
        }
        return $status;
    }

    /** What makes a release "changed" (compared as a whole, credits in order). */
    private static function content(string $title, string $type, string $date, string $artistName, string $artistMbid, ?array $credits, array $tags): array
    {
        return [
            'release' => [$title, $type, $date, $artistName, $artistMbid],
            'credits' => array_map(static fn ($c) => [$c['mbid'], $c['name'], $c['joinphrase']], $credits ?? []),
            'tags' => $tags,
        ];
    }

    // --- credited artists -------------------------------------------------------

    /**
     * Every credited artist of each release, in credit order.
     *
     * @param list<string> $ids
     * @return array<string, list<array{position: int, mbid: string, name: string, artist_name: string, joinphrase: string}>>
     *         by release id; releases without stored credits are missing
     */
    public function credits(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $s = $this->db->prepare('SELECT * FROM release_artists WHERE release_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY release_id, position');
            $s->execute($chunk);
            foreach ($s->fetchAll() as $row) {
                $out[$row['release_id']][] = ['position' => (int) $row['position'], 'mbid' => (string) $row['artist_mbid'],
                    'name' => (string) $row['name'], 'artist_name' => (string) $row['artist_name'], 'joinphrase' => (string) $row['joinphrase']];
            }
        }
        return $out;
    }

    /** Replaces a release's credited artists. */
    public function saveCredits(string $releaseId, array $credits): void
    {
        $this->db->prepare('DELETE FROM release_artists WHERE release_id = ?')->execute([$releaseId]);
        $ins = $this->db->prepare('INSERT INTO release_artists (release_id, position, artist_mbid, name, artist_name, joinphrase) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (array_values($credits) as $i => $c) {
            $ins->execute([$releaseId, $i, $c['mbid'], $c['name'], $c['artist_name'], $c['joinphrase']]);
        }
    }

    /** @return list<array<string, mixed>> releases without stored credits (from before credits were kept), newest first */
    public function releasesWithoutCredits(int $limit): array
    {
        $s = $this->db->prepare('SELECT id, title, artist_mbid, artist_name, primary_name, credited, origin FROM releases r
            WHERE NOT EXISTS (SELECT 1 FROM release_artists a WHERE a.release_id = r.id) ORDER BY first_release_date DESC, id LIMIT ?');
        $s->bindValue(1, $limit, PDO::PARAM_INT);
        $s->execute();
        return $s->fetchAll();
    }

    /**
     * Corrects a stored release's artist from its full MusicBrainz credit
     * (credits migration). Last.fm album data is dropped only if the first
     * artist changed - it was looked up by that name.
     */
    public function setArtist(string $id, array $artist): void
    {
        $old = $this->release($id);
        $this->db->prepare('UPDATE releases SET artist_mbid = ?, artist_name = ?, primary_name = ?, credited = ? WHERE id = ?')
            ->execute([$artist['mbid'], $artist['name'], $artist['primary_name'], count($artist['credits']), $id]);
        if ($old !== null && $old['primary_name'] !== $artist['primary_name']) {
            $this->db->prepare('UPDATE releases SET lastfm_album = NULL, lastfm_album_status = NULL, lastfm_album_at = NULL WHERE id = ?')->execute([$id]);
        }
        $this->saveCredits($id, $artist['credits']);
    }

    public function setWatchLevel(string $id, ?string $level): void
    {
        $this->db->prepare('UPDATE releases SET watch_level = ? WHERE id = ?')->execute([$level, $id]);
    }

    // --- Last.fm artist library --------------------------------------------------

    /**
     * Replaces the stored Last.fm artist library in one transaction (readers
     * see the old or the new library, never half of it).
     *
     * @param list<array<string, mixed>> $artists  see Taste\ListeningLibrary
     */
    public function replaceListening(array $artists, string $source, string $now): int
    {
        return $this->transaction(function () use ($artists, $source, $now): int {
            $this->db->exec('DELETE FROM listening_artists');
            $ins = $this->db->prepare('INSERT INTO listening_artists (name, name_key, lastfm_mbid, mbid, identity, plays_overall, plays_12month,
                plays_3month, rank_overall, source, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($artists as $a) {
                $ins->execute([$a['name'], mb_substr($a['name_key'], 0, 191), ($a['lastfm_mbid'] ?? '') ?: null, ($a['mbid'] ?? '') ?: null, $a['identity'],
                    (int) $a['plays']['overall'], (int) $a['plays']['12month'], (int) $a['plays']['3month'], $a['rank'] ?? null, $source, $now]);
            }
            return count($artists);
        });
    }

    /** @return list<array<string, mixed>> the stored library, most played first */
    public function listening(): array
    {
        // Row by row (11,000+ artists): only one copy in memory.
        $s = $this->db->query('SELECT name, name_key, lastfm_mbid, mbid, identity, rank_overall, plays_overall, plays_12month, plays_3month
            FROM listening_artists ORDER BY plays_overall DESC, id', PDO::FETCH_NUM);
        $out = [];
        foreach ($s as [$name, $key, $hint, $mbid, $identity, $rank, $overall, $year, $quarter]) {
            $out[] = ['name' => (string) $name, 'name_key' => (string) $key, 'lastfm_mbid' => (string) $hint, 'mbid' => (string) $mbid,
                'identity' => (string) $identity, 'rank' => $rank === null ? null : (int) $rank,
                'plays' => ['overall' => (int) $overall, '12month' => (int) $year, '3month' => (int) $quarter]];
        }
        return $out;
    }

    // --- Last.fm Similar Artists ---------------------------------------------------------

    /**
     * @param list<string> $keys  m:<mbid> | n:<name key>
     * @return array<string, array{artist_key: string, name: string, mbid: ?string, lastfm_page: ?string, identity: string, role: string,
     *   links: list<array{0: string, 1: string, 2: float}>, list_size: int, fetched_at: string}>
     */
    public function similar(array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 400) as $chunk) {
            $s = $this->db->prepare('SELECT * FROM artist_similar WHERE artist_key IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $s->execute($chunk);
            foreach ($s->fetchAll() as $r) {
                $r['links'] = json_decode((string) $r['links'], true) ?: [];
                $r['list_size'] = (int) $r['list_size'];
                $out[$r['artist_key']] = $r;
            }
        }
        return $out;
    }

    /** @param list<array{0: string, 1: string, 2: float}> $links [name, mbid, match] */
    public function saveSimilar(string $key, string $name, ?string $mbid, ?string $page, string $identity, string $role, array $links, int $listSize, string $now): void
    {
        $this->upsert('artist_similar', ['artist_key'], ['artist_key' => mb_substr($key, 0, 191), 'name' => $name, 'mbid' => $mbid ?: null,
            'lastfm_page' => $page, 'identity' => $identity, 'role' => $role, 'links' => json_encode($links, JSON_UNESCAPED_UNICODE),
            'list_size' => $listSize, 'fetched_at' => $now]);
    }

    /** @return array{artists: int, updated_at: ?string} */
    public function listeningSummary(): array
    {
        $r = $this->db->query('SELECT COUNT(*) n, MAX(updated_at) u FROM listening_artists')->fetch();
        return ['artists' => (int) $r['n'], 'updated_at' => $r['u']];
    }

    public function release(string $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM releases WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> releases first released in [from, to] (or partial dates from known artists) */
    public function releasesInWindow(string $from, string $to): array
    {
        $s = $this->db->prepare("SELECT * FROM releases WHERE (first_release_date BETWEEN ? AND ?) OR (origin != 'pool' AND length(first_release_date) < 10 AND first_release_date >= ?) ORDER BY first_release_date DESC, id");
        $s->execute([$from, $to, substr($from, 0, 4)]);
        return $s->fetchAll();
    }

    public function setPrescore(string $id, float $fit, string $quality): void
    {
        $this->db->prepare('UPDATE releases SET prescore = ?, prescore_quality = ? WHERE id = ?')->execute([$fit, $quality, $id]);
    }

    public function setLastfmAlbum(string $id, ?array $album, string $status, string $now): void
    {
        $this->db->prepare('UPDATE releases SET lastfm_album = ?, lastfm_album_status = ?, lastfm_album_at = ? WHERE id = ?')
            ->execute([$album === null ? null : json_encode($album), $status, $now, $id]);
    }

    // --- artists --------------------------------------------------------------

    /** @return list<string> MBIDs of artists in the window without (fresh) MusicBrainz data */
    public function artistsNeedingMusicBrainz(string $from, string $to, string $staleBefore): array
    {
        $s = $this->db->prepare("SELECT DISTINCT r.artist_mbid FROM releases r LEFT JOIN artists a ON a.mbid = r.artist_mbid
            WHERE (r.first_release_date BETWEEN ? AND ? OR r.origin != 'pool')
              AND (a.mbid IS NULL OR a.mb_fetched_at IS NULL OR a.mb_fetched_at < ?)");
        $s->execute([$from, $to, $staleBefore]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    }

    public function saveArtistMusicBrainz(string $mbid, ?array $data, string $now): void
    {
        $old = $this->artist($mbid);
        $this->upsert('artists', ['mbid'], [
            'mbid' => $mbid,
            'name' => $data['name'] ?? $old['name'] ?? null,
            'mb' => $data === null ? null : json_encode($data),
            'mb_status' => $data === null ? 'not_found' : 'ok',
            'mb_fetched_at' => $now,
            'lastfm' => $old['lastfm'] ?? null,
            'lastfm_status' => $old['lastfm_status'] ?? null,
            'lastfm_fetched_at' => $old['lastfm_fetched_at'] ?? null,
        ]);
    }

    public function saveArtistLastfm(string $mbid, string $name, ?array $data, string $status, string $now): void
    {
        $old = $this->artist($mbid);
        $this->upsert('artists', ['mbid'], [
            'mbid' => $mbid,
            'name' => $old['name'] ?? $name,
            'mb' => $old['mb'] ?? null,
            'mb_status' => $old['mb_status'] ?? null,
            'mb_fetched_at' => $old['mb_fetched_at'] ?? null,
            'lastfm' => $data === null ? null : json_encode($data),
            'lastfm_status' => $status,
            'lastfm_fetched_at' => $now,
        ]);
    }

    public function artist(string $mbid): ?array
    {
        $s = $this->db->prepare('SELECT * FROM artists WHERE mbid = ?');
        $s->execute([$mbid]);
        return $s->fetch() ?: null;
    }

    /** @return array<string, array<string, mixed>> by MBID */
    public function artistsByMbid(array $mbids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($mbids)), 500) as $chunk) {
            $s = $this->db->prepare('SELECT * FROM artists WHERE mbid IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $s->execute($chunk);
            foreach ($s->fetchAll() as $row) {
                $out[$row['mbid']] = $row;
            }
        }
        return $out;
    }

    // --- slices, failures, meta ---------------------------------------------------

    /** @return list<array<string, mixed>> stored releases of one day and type ("Album" / "EP") */
    public function releasesOn(string $day, string $type): array
    {
        $s = $this->db->prepare('SELECT * FROM releases WHERE first_release_date = ? AND type = ? ORDER BY id');
        $s->execute([$day, $type]);
        return $s->fetchAll();
    }

    public function slice(string $day, string $type): ?array
    {
        $s = $this->db->prepare('SELECT * FROM slices WHERE day = ? AND type = ?');
        $s->execute([$day, $type]);
        return $s->fetch() ?: null;
    }

    public function saveSlice(string $day, string $type, array $stats, string $now): void
    {
        $this->upsert('slices', ['day', 'type'], ['day' => $day, 'type' => $type, 'collected_at' => $now,
            'hits' => $stats['hits'], 'exact' => count($stats['release_groups']), 'partitions' => $stats['partitions'], 'unreachable' => $stats['over_limit']]);
    }

    /** @return list<array<string, mixed>> */
    public function slices(string $from, string $to): array
    {
        $s = $this->db->prepare('SELECT * FROM slices WHERE day BETWEEN ? AND ? ORDER BY day, type');
        $s->execute([$from, $to]);
        return $s->fetchAll();
    }

    public function fail(string $stage, string $item, string $message, string $now): void
    {
        $old = $this->db->prepare('SELECT attempts FROM failures WHERE stage = ? AND item = ?');
        $old->execute([$stage, $item]);
        $attempts = (int) ($old->fetchColumn() ?: 0) + 1;
        $this->upsert('failures', ['stage', 'item'], ['stage' => $stage, 'item' => $item, 'message' => mb_substr($message, 0, 500), 'attempts' => $attempts, 'last_at' => $now]);
    }

    public function clearFailure(string $stage, string $item): void
    {
        $this->db->prepare('DELETE FROM failures WHERE stage = ? AND item = ?')->execute([$stage, $item]);
    }

    /**
     * Items of a stage that failed $max times, the last time after $since:
     * paused so a broken item doesn't eat every short run. Retried once the
     * pause is over.
     *
     * @return array<string, true>
     */
    public function pausedFailures(string $stage, int $max, string $since): array
    {
        $s = $this->db->prepare('SELECT item FROM failures WHERE stage = ? AND attempts >= ? AND last_at > ?');
        $s->execute([$stage, $max, $since]);
        return array_fill_keys($s->fetchAll(\PDO::FETCH_COLUMN), true);
    }

    /**
     * Forgets failures of items that have left the window: they are never
     * retried, so they would stay listed as "waiting for retry" forever.
     * Collected days before $from, and Last.fm / credits lookups of releases
     * with a full date before $from. Artist-level stages are not tied to the
     * window and are kept.
     */
    public function forgetFailuresBefore(string $from): int
    {
        $collect = $this->db->prepare("DELETE FROM failures WHERE stage = 'collect' AND item < ?");
        $collect->execute([$from]);
        $releases = $this->db->prepare("DELETE FROM failures WHERE stage IN ('lastfm', 'credits')
            AND item IN (SELECT id FROM releases WHERE length(first_release_date) = 10 AND first_release_date < ?)");
        $releases->execute([$from]);
        return $collect->rowCount() + $releases->rowCount();
    }

    /** @return list<array<string, mixed>> */
    public function failures(): array
    {
        return $this->db->query('SELECT * FROM failures ORDER BY last_at DESC')->fetchAll();
    }

    public function meta(string $k): ?string
    {
        $s = $this->db->prepare('SELECT v FROM meta WHERE k = ?');
        $s->execute([$k]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function setMeta(string $k, string $v): void
    {
        $this->upsert('meta', ['k'], ['k' => $k, 'v' => $v]);
    }
}
