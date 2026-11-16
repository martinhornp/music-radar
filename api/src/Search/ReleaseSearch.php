<?php

declare(strict_types=1);

namespace MusicRadar\Search;

use MusicRadar\Discovery\FactBuilder;
use MusicRadar\Feed\FeedBuilder;
use MusicRadar\Taste\TagClassifier;
use PDO;
use PDOException;

/**
 * Search in everything the discovery pipeline has stored (the releases
 * table), not only in the current recommendations. Read-only: nothing is
 * fetched from MusicBrainz or Last.fm.
 *
 * Matching: every word of the query must occur in the artist name or the
 * title. Words of 3+ letters use a FULLTEXT index (word prefix: "knock"
 * finds "Knocked Loose"); short words and very common words ("the", "of")
 * are matched as text. Upper/lower case and accents are ignored by the
 * database collation ("sodt" finds "Sødt").
 */
final class ReleaseSearch
{
    public const MAX_RESULTS = 30;
    private const INDEX = 'releases_search';
    // InnoDB's default full-text stopwords: they are never indexed, so they
    // must not be required words in a full-text query.
    private const STOPWORDS = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in',
        'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www'];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The words of a query: letters and digits only (so punctuation, quotes
     * and full-text operators can never reach the query syntax).
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(mb_substr($query, 0, 100))) ?: [];
        return array_values(array_unique(array_filter($parts, static fn ($w) => $w !== '')));
    }

    /**
     * @param bool $plainOnly  match every word as text, without the index
     * @return array{rows: list<array>, more: bool}  matching releases, best first
     */
    public function search(string $query, int $limit = self::MAX_RESULTS, bool $plainOnly = false): array
    {
        $words = self::words($query);
        if ($words === []) {
            return ['rows' => [], 'more' => false];
        }
        $indexed = $plainOnly ? [] : array_values(array_filter($words, static fn ($w) => mb_strlen($w) >= 3 && !in_array($w, self::STOPWORDS, true)));
        $plain = array_values(array_diff($words, $indexed));

        $where = [];
        $params = [];
        if ($indexed !== []) {
            $where[] = 'MATCH(title, artist_name) AGAINST(? IN BOOLEAN MODE)';
            $params[] = implode(' ', array_map(static fn ($w) => "+$w*", $indexed));
        }
        foreach ($plain as $w) {
            $where[] = '(title LIKE ? OR artist_name LIKE ?)';
            $like = '%' . addcslashes($w, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        // Best first: exact artist, artist starts with the query, title starts with it, then newest.
        $phrase = trim((string) preg_replace('/\s+/u', ' ', mb_substr($query, 0, 100)));
        $sql = 'SELECT id, title, type, first_release_date, artist_mbid, artist_name, primary_name, credited, release_tags, origin,
                watch_level, lastfm_album, lastfm_album_status, first_seen_at
            FROM releases WHERE ' . implode(' AND ', $where) . '
            ORDER BY (artist_name = ?) DESC, (artist_name LIKE ?) DESC, (title LIKE ?) DESC, first_release_date DESC, title
            LIMIT ' . ($limit + 1);
        array_push($params, $phrase, addcslashes($phrase, '%_\\') . '%', addcslashes($phrase, '%_\\') . '%');

        try {
            $rows = $this->run($sql, $params);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1191) { // 1191 = no FULLTEXT index yet
                throw $e;
            }
            $this->createIndex();
            $rows = $this->run($sql, $params);
        }
        if ($rows === [] && $indexed !== []) {
            // Nothing via the index: plain text matching for every word - finds
            // the middle of words and "ø" typed as "o" (a table scan, but only here).
            return $this->search($query, $limit, true);
        }
        return ['rows' => array_slice($rows, 0, $limit), 'more' => count($rows) > $limit];
    }

    /** One stored release by id, or null. */
    public function find(string $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM releases WHERE id = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** What the database covers, for an honest "not found". */
    public function coverage(): array
    {
        $r = $this->db->query("SELECT COUNT(*) n, MIN(first_release_date) f, MAX(first_release_date) t FROM releases WHERE LENGTH(first_release_date) = 10")->fetch(PDO::FETCH_ASSOC);
        return ['releases' => (int) $r['n'], 'from' => $r['f'], 'to' => $r['t']];
    }

    /**
     * A stored release in the feed format (the same shape the site shows),
     * built only from documented facts - for releases that were not
     * recommended: no reasons, no score.
     */
    public function toFeedEntry(array $row): array
    {
        $artist = $this->artist((string) $row['artist_mbid']);
        $release = [
            'id' => $row['id'],
            'title' => $row['title'],
            'type' => $row['type'],
            'first_release_date' => $row['first_release_date'],
            'artist' => ['name' => $row['artist_name'], 'mbid' => $row['artist_mbid'], 'primary_name' => $row['primary_name'], 'credited_artists' => (int) $row['credited']],
            'release_tags' => json_decode((string) $row['release_tags'], true) ?: [],
        ];
        $mb = $artist && $artist['mb'] ? json_decode($artist['mb'], true) : null;
        $lfArtist = $artist && $artist['lastfm_status'] === 'found' ? json_decode($artist['lastfm'], true) : null;
        $lfAlbum = $row['lastfm_album_status'] === 'found' ? json_decode((string) $row['lastfm_album'], true) : null;
        $release += FactBuilder::build($release, $mb, $lfArtist, $lfAlbum, $artist !== null && $artist['lastfm_fetched_at'] !== null);

        $origin = in_array($row['watch_level'], ['following', 'interested'], true) ? $row['watch_level'] : 'discovery';
        $pick = ['type' => 'none', 'angle' => null, 'origin' => $origin, 'data_quality' => ($release['metadata_quality'] ?? '') === 'good' ? 'good' : 'limited'];
        return FeedBuilder::entry($pick, 0, $release, self::family($release['genre_tags'] ?? []), []);
    }

    /**
     * The genre family of documented tags: the most common family among the
     * specific tags (broad ones like "rock" or "pop" only count when there is
     * nothing more specific) - the same idea the engine uses. Null without tags.
     */
    public static function family(array $tags): ?string
    {
        static $generic = null;
        $generic ??= (require __DIR__ . '/../../config/recommender.php')['generic_tags'];
        $count = static function (array $list): array {
            $c = [];
            foreach ($list as $t) {
                $f = TagClassifier::family(TagClassifier::canonical((string) $t));
                if ($f !== 'Other') {
                    $c[$f] = ($c[$f] ?? 0) + 1;
                }
            }
            arsort($c);
            return $c;
        };
        $families = $count(array_filter($tags, static fn ($t) => !in_array(TagClassifier::canonical((string) $t), $generic, true)))
            ?: $count($tags);
        return array_key_first($families);
    }

    private function artist(string $mbid): ?array
    {
        $s = $this->db->prepare('SELECT * FROM artists WHERE mbid = ?');
        $s->execute([$mbid]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function run(string $sql, array $params): array
    {
        $s = $this->db->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    /** One-time: the full-text index (created on the first search; ~4,000 rows take well under a second). */
    private function createIndex(): void
    {
        $this->db->exec('ALTER TABLE releases ADD FULLTEXT INDEX ' . self::INDEX . ' (title, artist_name)');
    }
}
