<?php

declare(strict_types=1);

namespace MusicRadar\Feed;

use MusicRadar\Taste\TagClassifier;

/**
 * Turns the engine's picks into the feed the website shows (the contract of
 * GET /api/recommendations): only display fields - no scores, listening
 * counts or raw facts. Used by the server import and by export-feed.php.
 */
final class FeedBuilder
{
    /**
     * @param array<string, array> $releases     built releases by id (with facts)
     * @param array<string, array> $assessments  engine assessments by id
     * @param callable|null $reasons  fn(array $pick, array $release): list<reason> to
     *                                re-word reasons; default: the pick's own reasons
     */
    public static function feed(array $recommended, array $wildcards, array $releases, array $assessments, array $profile,
        string $generatedAt, array $window, int $examined, ?callable $reasons = null): array
    {
        $items = [];
        foreach (['recommended' => $recommended, 'wildcards' => $wildcards] as $list) {
            foreach (array_values($list) as $i => $pick) {
                $r = $releases[$pick['id']];
                $items[] = self::entry($pick, $i + 1, $r, $assessments[$pick['id']]['primary_family'] ?? null,
                    $reasons ? $reasons($pick, $r) : $pick['reasons']);
            }
        }
        return [
            'generatedAt' => $generatedAt,
            'window' => array_values($window),
            'examined' => $examined,
            'taste' => self::taste($profile),
            'releases' => $items,
        ];
    }

    public static function entry(array $pick, int $rank, array $r, ?string $genre, array $reasons): array
    {
        // Only genre tags (no places, nationalities or noise), strongest first.
        $tags = array_values(array_unique(array_map(
            TagClassifier::canonical(...),
            array_filter($r['genre_tags'] ?? [], static fn ($t) => TagClassifier::classify($t) === 'genre'),
        )));
        $sources = array_map(
            static fn ($f) => str_starts_with($f['source'], 'lastfm') ? 'Last.fm' : 'MusicBrainz',
            $r['facts'] ?? [],
        );
        // Last.fm Similar Artists counted in the score: credit Last.fm.
        if (($pick['score_parts']['lastfm'] ?? 0) > 0) {
            $sources[] = 'Last.fm';
        }
        $sources = array_values(array_unique($sources));
        return [
            'id' => $r['id'],
            'artist' => $r['artist']['name'],
            // Lets a rating teach the engine about this artist's future releases.
            'artistMbid' => $r['artist']['mbid'] ?? null,
            'title' => $r['title'],
            'type' => $r['type'] === 'EP' ? 'EP' : 'Album',
            'releaseDate' => $r['first_release_date'],
            'genre' => $genre,
            'tags' => array_slice($tags, 0, 5),
            'country' => $r['country'] ?? null,
            'origin' => $pick['origin'],
            'dataQuality' => $pick['data_quality'],
            // The engine's own score (fit), unchanged - a ranking value, not a
            // probability. Null for releases the engine did not pick (search results).
            'score' => isset($pick['fit']) ? (float) $pick['fit'] : null,
            // What the score is made of: each signal's contribution (they add up to the score).
            'scoreParts' => isset($pick['fit']) ? array_map('floatval', $pick['score_parts'] ?? []) : null,
            // Short reason, MusicBrainz link and cover URL are derived in the frontend.
            'pick' => ['kind' => $pick['type'], 'angle' => $pick['angle'], 'rank' => $rank],
            'reasons' => array_map(static fn ($x) => ['kind' => $x['kind'], 'text' => $x['text']], $reasons),
            'sources' => $sources,
        ];
    }

    /**
     * The run's current score for every release it could assess - picked or
     * not - for My Music (the score a rated release has now, not the one it
     * had when you rated it).
     *
     * @param array<string, array> $assessments engine assessments by id
     * @return array<string, array{score: float, scoreParts: array<string, float>}>
     */
    public static function scores(array $assessments): array
    {
        $out = [];
        foreach ($assessments as $id => $a) {
            if (($a['assessable'] ?? false) && isset($a['fit'])) {
                $out[$id] = ['score' => (float) $a['fit'], 'scoreParts' => array_map('floatval', $a['score_parts'] ?? [])];
            }
        }
        return $out;
    }

    /** "What the picks are based on": top genre families and your core artists. */
    public static function taste(array $profile): array
    {
        $core = array_filter($profile['artists'] ?? [], static fn ($a) => ($a['lastfm']['taste'] ?? true)
            && in_array($a['lastfm']['listening'] ?? '', ['core', 'current'], true));
        usort($core, static fn ($a, $b) => ($b['lastfm']['periods']['overall']['plays'] ?? 0) <=> ($a['lastfm']['periods']['overall']['plays'] ?? 0));
        return [
            'genres' => array_slice(array_column($profile['families'] ?? [], 'name'), 0, 6),
            'artists' => array_slice(array_column($core, 'name'), 0, 10),
        ];
    }
}
