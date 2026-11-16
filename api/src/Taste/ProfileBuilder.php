<?php

declare(strict_types=1);

namespace MusicRadar\Taste;

use MusicRadar\LastFm\LastFmClient;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\MusicBrainz\MusicBrainzException;

/**
 * Builds the listener's music profile (schema documented in
 * docs/music-profile.md) from Last.fm listening data, identifies every artist
 * in MusicBrainz and decides how to watch it (following / interested). Read-only;
 * the result is a plain array that can later be stored in the database.
 */
final class ProfileBuilder
{
    public const SCHEMA_VERSION = 1;
    public const PERIODS = ['overall', '12month', '3month'];

    /**
     * How much each listening layer counts in the genre profile. Dormant
     * favourites keep a real share: quiet lately is not the same as disliked.
     */
    public const LAYER_WEIGHTS = ['core' => 0.35, 'current' => 0.30, 'recent' => 0.20, 'dormant' => 0.15];

    /**
     * The profile holds your Last.fm top 100 per period - all of them are
     * Interested (their new releases are always shown), and all of them form
     * the musical taste profile (genres, "close to" artists, Last.fm links,
     * Danish share): a broad taste needs more than the top 30.
     */
    public const TASTE_TOP = 100;

    /** @var callable(string): void */
    private $log;
    /** @var array<string, array<string, mixed>> previous profile's artists by name */
    private array $previousArtists;

    /**
     * @param array<string, array{mbid?: string|false, level?: string, note?: string}> $overrides
     * @param array<string, mixed>|null $previousProfile  last generated profile: artists in it
     *        that are no longer in the Last.fm top lists are kept ("retained")
     */
    public function __construct(
        private readonly LastFmClient $lastfm,
        private readonly MusicBrainzClient $musicbrainz,
        private readonly array $overrides = [],
        ?callable $log = null,
        private readonly ?array $previousProfile = null,
    ) {
        $this->log = $log ?? static function (string $msg): void {
        };
        $this->previousArtists = array_column($previousProfile['artists'] ?? [], null, 'name');
    }

    public function build(string $user, int $topN = 100): array
    {
        $artists = self::retain($this->collectListening($user, $topN), $this->previousProfile, $this->overrides);
        ($this->log)(sprintf(
            'Last.fm: %d artists (%d kept from the previous profile or added by you)',
            count($artists),
            count(array_filter($artists, static fn ($a) => $a['periods'] === [])),
        ));
        foreach ($artists as $name => &$a) {
            $this->tasteAndLayer($name, $a);
        }
        unset($a);
        foreach (self::playWeights($artists) as $name => $w) {
            $artists[$name]['play_weight'] = $w;
        }

        foreach ($artists as $name => &$a) {
            $tags = $this->lastfm->artistTopTags($name)['tags'];
            usleep(200_000);
            $split = TagClassifier::split($tags, $name);
            $a['lastfm_tags'] = array_slice(array_column($tags, 'name'), 0, 8);
            $a['lastfm_genres'] = $split['genres'];
            $a['lastfm_meta'] = $split['meta'];
            $a['countries'] = TagClassifier::countries($split['meta']);
            $a['families'] = self::families($split['genres']);
            $a['tags_suspect'] = self::looksMixed($a['families']);
        }
        unset($a);

        $profileFamilies = $this->preliminaryFamilies($artists);

        $i = 0;
        foreach ($artists as $name => &$a) {
            ($this->log)(sprintf('MusicBrainz %d/%d: %s', ++$i, count($artists), $name));
            $a['match'] = $this->reusedMatch($name, $a) ?? $this->identify($name, $a, $profileFamilies);
            $a['watch'] = FollowPolicy::decide($a['periods'], $this->overrides[$name]['level'] ?? null, $a['retained']);
        }
        unset($a);

        $entries = array_map($this->artistEntry(...), array_values($artists));
        $taste = array_values(array_filter($entries, static fn ($e) => $e['lastfm']['taste']));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'user' => $user,
            'generated_at' => date(DATE_ATOM),
            'sources' => [
                'lastfm' => ['periods' => self::PERIODS, 'top_n' => $topN, 'taste_top' => self::TASTE_TOP, 'taste_artists' => count($taste)],
                'musicbrainz' => self::matchSummary($entries),
                'musicbrainz_reused' => count(array_filter($artists, static fn ($a) => $a['match_reused'] ?? false)),
                'previous_profile' => $this->previousProfile['generated_at'] ?? null,
            ],
            'watch_summary' => array_count_values(array_map(static fn ($e) => $e['watch']['level'], $entries)),
            'layer_weights' => self::LAYER_WEIGHTS,
            'genres' => self::genreProfile($taste, 'genres'),
            'families' => self::genreProfile($taste, 'families'),
            'attributes' => self::attributes($taste),
            'ratings' => [
                'count' => 0,
                'share_of_profile' => RatingInfluence::share(0),
                'model' => sprintf('share = %.1f * n / (n + %d); per-genre signal shrunk toward 3 stars by %d neutral ratings',
                    RatingInfluence::MAX, RatingInfluence::K, RatingInfluence::PRIOR),
            ],
            'artists' => $entries,
        ];
    }

    /** @return array<string, array<string, mixed>> keyed by Last.fm name */
    private function collectListening(string $user, int $topN): array
    {
        $artists = [];
        foreach (self::PERIODS as $period) {
            foreach ($this->lastfm->userTopArtists($user, $period, $topN) as $i => $row) {
                $a = &$artists[$row['name']];
                $a['name'] = $row['name'];
                $a['periods'][$period] = ['rank' => $i + 1, 'plays' => $row['playcount']];
                $a['lastfm_mbid'] = ($a['lastfm_mbid'] ?? '') ?: $row['mbid'];
                unset($a);
            }
        }
        foreach ($artists as &$a) {
            $a['listening'] = self::listeningStatus($a['periods']);
            $a['retained'] = false;
        }
        return $artists;
    }

    /**
     * Nothing disappears automatically. Adds to the current Last.fm artists:
     *  - artists from the previous profile that dropped out of the top lists
     *  - artists you added by hand (level set in overrides)
     * They get no periods (no current Last.fm rank) and count as "dormant".
     *
     * @param array<string, array<string, mixed>> $current keyed by name
     * @return array<string, array<string, mixed>>
     */
    public static function retain(array $current, ?array $previous, array $overrides): array
    {
        $blank = static fn (string $name, bool $retained) => [
            'name' => $name, 'periods' => [], 'lastfm_mbid' => '', 'listening' => 'dormant', 'retained' => $retained,
        ];
        foreach ($previous['artists'] ?? [] as $old) {
            $name = (string) $old['name'];
            if (!isset($current[$name])) {
                $current[$name] = $blank($name, true);
                $current[$name]['lastfm_mbid'] = (string) ($old['lastfm']['mbid_hint'] ?? '');
            }
        }
        foreach ($overrides as $name => $o) {
            if (!isset($current[$name]) && in_array($o['level'] ?? null, [FollowPolicy::FOLLOWING, FollowPolicy::INTERESTED], true)) {
                $current[$name] = $blank((string) $name, false);
            }
        }
        return $current;
    }

    /**
     * Whether an artist is part of the musical taste profile, and its
     * listening layer:
     *  - in the taste profile: a placement within TASTE_TOP in some period,
     *    or kept from the previous taste profile; its layer comes from those
     *    placements (each artist once, however many periods it is in)
     *  - otherwise: Interested, but not part of the taste; layer from its
     *    placements (wording only)
     */
    private function tasteAndLayer(string $name, array &$a): void
    {
        $top = array_filter($a['periods'], static fn ($p) => $p['rank'] <= self::TASTE_TOP);
        $previous = $this->previousArtists[$name] ?? null;
        // Profiles from before this flag only held taste artists. An artist added
        // by hand (no ranks, not seen before) counts, as it always did.
        $wasTaste = (bool) ($previous['lastfm']['taste'] ?? true);
        $a['taste'] = $top !== [] || ($wasTaste && ($previous !== null || $a['periods'] === []));
        $a['listening'] = self::listeningStatus($a['taste'] ? $top : $a['periods']);
    }

    /**
     * The MusicBrainz match from the previous profile, when it was confirmed
     * and nothing it was based on changed (same Last.fm id hint, no manual
     * override) - saves the weekly searches (MusicBrainz allows 1 request/s).
     */
    private function reusedMatch(string $name, array &$a): ?ArtistMatch
    {
        $old = $this->previousArtists[$name] ?? null;
        $m = $old['musicbrainz'] ?? null;
        if ($m === null || ($m['status'] ?? null) !== ArtistMatch::CONFIRMED || isset($this->overrides[$name])
            || (string) ($old['lastfm']['mbid_hint'] ?? '') !== $a['lastfm_mbid']) {
            return null;
        }
        $a['match_reused'] = true;
        return new ArtistMatch($m['status'], $m['mbid'], $m['confidence'], $m['source'], $m['evidence'] ?? [], $m['problems'] ?? [],
            $m['candidates'] ?? [], $m['musicbrainz_name'] ?? null, $m['country'] ?? null, $m['disambiguation'] ?? null, $m['musicbrainz_tags'] ?? []);
    }

    private function identify(string $name, array $a, array $profileFamilies): ArtistMatch
    {
        $override = $this->overrides[$name] ?? [];
        $note = isset($override['note']) ? [$override['note']] : [];

        if (array_key_exists('mbid', $override)) {
            if ($override['mbid'] === false) {
                return new ArtistMatch(ArtistMatch::MANUAL_NONE, null, 'high', 'manual', ['you confirmed there is no MusicBrainz entry', ...$note]);
            }
            try {
                $r = $this->musicbrainz->lookupArtist((string) $override['mbid']);
                $tags = array_map(static fn ($t) => (string) $t['name'], [...($r['genres'] ?? []), ...($r['tags'] ?? [])]);
                return new ArtistMatch(ArtistMatch::MANUAL, (string) $r['id'], 'high', 'manual', ['you chose this MusicBrainz artist', ...$note],
                    [], [], $r['name'] ?? null, $r['country'] ?? null, ($r['disambiguation'] ?? '') ?: null, array_values(array_unique($tags)));
            } catch (MusicBrainzException $e) {
                return new ArtistMatch(ArtistMatch::NEEDS_REVIEW, null, 'none', 'manual', [], ['manual MBID could not be loaded: ' . $e->getMessage()]);
            }
        }

        try {
            $results = $this->musicbrainz->searchArtists($name);
            $lastfmRecord = null;
            if ($a['lastfm_mbid'] !== '' && !in_array($a['lastfm_mbid'], array_column($results, 'id'), true)) {
                try {
                    $lastfmRecord = $this->musicbrainz->lookupArtist($a['lastfm_mbid']);
                } catch (MusicBrainzException) {
                    $lastfmRecord = ['id' => $a['lastfm_mbid'], 'name' => '(unknown or deleted MusicBrainz ID)'];
                }
            }
        } catch (MusicBrainzException $e) {
            return new ArtistMatch(ArtistMatch::NEEDS_REVIEW, null, 'none', 'musicbrainz_search', [], ['MusicBrainz lookup failed: ' . $e->getMessage()]);
        }

        return ArtistMatcher::match(
            $name,
            $a['lastfm_mbid'],
            $a['tags_suspect'] ? [] : array_keys($a['families']),
            $a['countries'],
            $a['tags_suspect'],
            $results,
            $lastfmRecord,
            $profileFamilies,
        );
    }

    private function artistEntry(array $a): array
    {
        /** @var ArtistMatch $match */
        $match = $a['match'];
        $issues = [];

        // Which genre data to trust for the profile.
        if ($a['lastfm_genres'] !== [] && !$a['tags_suspect']) {
            $genres = $a['lastfm_genres'];
            $genreSource = 'lastfm';
        } elseif ($match->isUsable() && TagClassifier::familiesOf($match->mbTags) !== []) {
            $mb = array_values(array_filter($match->mbTags, static fn ($t) => TagClassifier::classify($t) === 'genre'));
            $genres = array_fill_keys(array_map(TagClassifier::canonical(...), $mb), 1 / max(count($mb), 1));
            $genreSource = 'musicbrainz';
        } else {
            $genres = [];
            $genreSource = 'none';
        }
        if ($a['tags_suspect']) {
            $issues[] = 'Last.fm tags mix unrelated styles (likely several artists with this name)';
        }
        if ($genres === []) {
            $issues[] = 'no reliable genre data - kept, but not used in the genre profile';
        }
        if (!$match->isUsable() && $match->status !== ArtistMatch::MANUAL_NONE) {
            $issues[] = match ($match->status) {
                ArtistMatch::NEEDS_REVIEW => 'MusicBrainz match needs your review',
                ArtistMatch::NOT_FOUND => 'not found on MusicBrainz',
                default => 'no MusicBrainz match',
            };
        }

        $level = $a['watch']['level'];
        $watched = $level !== FollowPolicy::IGNORED;
        $blocked = match (true) {
            !$watched => null,
            $match->isUsable() => null,
            $match->status === ArtistMatch::MANUAL_NONE => 'no MusicBrainz entry - needs another source',
            default => 'needs a confirmed MusicBrainz ID first',
        };
        if ($blocked !== null) {
            $issues[] = "new releases can't be detected yet: $blocked";
        }

        return [
            'name' => $a['name'],
            'lastfm' => [
                'periods' => $a['periods'],
                'listening' => $a['listening'],
                'retained' => $a['retained'],
                // Part of the musical taste profile (TASTE_TOP) - or Interested only.
                'taste' => $a['taste'],
                // How much this artist counts in the genre profile (see playWeights()).
                'play_weight' => round($a['play_weight'] ?? 1.0, 3),
                'mbid_hint' => $a['lastfm_mbid'] ?: null,
                'top_tags' => $a['lastfm_tags'],
            ],
            'musicbrainz' => $match->toArray(),
            'genres' => [
                'source' => $genreSource,
                'weights' => array_map(static fn ($w) => round($w, 3), $genres),
                'families' => array_map(static fn ($w) => round($w, 3), self::families($genres)),
            ],
            'country' => $match->country ?? ($a['countries'][0] ?? null),
            'country_source' => $match->country !== null ? 'musicbrainz' : ($a['countries'] ? 'lastfm_tags' : null),
            'watch' => $a['watch'],
            'monitoring' => [
                'enabled' => $watched && $blocked === null,
                // following: new albums/EPs always reach "Recommended for You";
                // interested: always detected, then evaluated against the profile
                'releases' => match ($level) {
                    FollowPolicy::FOLLOWING => 'always_include',
                    FollowPolicy::INTERESTED => 'detect_and_evaluate',
                    default => 'ignore',
                },
                'blocked_reason' => $blocked,
                'last_checked_at' => null,
                'last_release_found_at' => null,
                'last_error' => null,
            ],
            'issues' => $issues,
        ];
    }

    // ---------------------------------------------------------------------

    /**
     * How much each artist counts in the genre profile, from its plays - damped,
     * so the most played artists count most without taking over, and #95 still
     * counts: sqrt(plays / plays of that period's #1), per period (your #1 = 1,
     * an artist with a quarter of its plays = 0.5); an artist in several periods
     * counts once, with its best period. Artists kept from earlier profiles (no
     * current plays) get the lowest weight of the ranked ones.
     *
     * @param array<string, array<string, mixed>> $artists by name, with periods
     * @return array<string, float>
     */
    public static function playWeights(array $artists): array
    {
        $top = [];
        foreach ($artists as $a) {
            foreach ($a['periods'] ?? [] as $period => $p) {
                $top[$period] = max($top[$period] ?? 0, (int) $p['plays']);
            }
        }
        $weights = [];
        foreach ($artists as $name => $a) {
            $best = null;
            foreach ($a['periods'] ?? [] as $period => $p) {
                $w = $top[$period] > 0 ? sqrt((int) $p['plays'] / $top[$period]) : 1.0;
                $best = max($best ?? 0.0, $w);
            }
            $weights[$name] = $best;
        }
        $ranked = array_filter($weights, static fn ($w) => $w !== null);
        $floor = $ranked !== [] ? min($ranked) : 1.0;
        return array_map(static fn ($w) => $w ?? $floor, $weights);
    }

    /** @param array<string, array{rank: int, plays: int}> $periods */
    private static function listeningStatus(array $periods): string
    {
        return match (true) {
            count($periods) === 3 => 'core',
            isset($periods['3month']) => 'current',
            isset($periods['12month']) => 'recent',
            default => 'dormant',
        };
    }

    /** @param array<string, float> $genres @return array<string, float> */
    private static function families(array $genres): array
    {
        $f = [];
        foreach ($genres as $g => $w) {
            $fam = TagClassifier::family($g);
            $f[$fam] = ($f[$fam] ?? 0) + $w;
        }
        arsort($f);
        return $f;
    }

    /** Heavy styles and pop both strongly present = probably several artists. */
    private static function looksMixed(array $families): bool
    {
        $heavy = array_sum(array_intersect_key($families, array_flip(['Hardcore', 'Metalcore', 'Metal', 'Punk', 'Post-hardcore & emo'])));
        $soft = array_sum(array_intersect_key($families, array_flip(['Pop', 'Latin & reggaeton'])));
        return $heavy >= 0.2 && $soft >= 0.2;
    }

    /** Families from Last.fm only, strongest first - used to rank review candidates. */
    private function preliminaryFamilies(array $artists): array
    {
        $sum = [];
        foreach ($artists as $a) {
            if (!$a['tags_suspect']) {
                foreach ($a['families'] as $f => $w) {
                    $sum[$f] = ($sum[$f] ?? 0) + $w;
                }
            }
        }
        arsort($sum);
        unset($sum['Other']);
        return array_keys($sum);
    }

    /**
     * Weighted genre profile: per listening layer the average of its artists,
     * each weighted by its plays (play_weight, damped - see playWeights()),
     * then the layers combined by LAYER_WEIGHTS.
     */
    private static function genreProfile(array $entries, string $field): array
    {
        $key = $field === 'genres' ? 'weights' : 'families';
        $layers = [];
        $mass = [];
        foreach ($entries as $e) {
            if ($e['genres'][$key] !== []) {
                $layer = $e['lastfm']['listening'];
                $layers[$layer][] = [$e['genres'][$key], (float) ($e['lastfm']['play_weight'] ?? 1.0)];
                $mass[$layer] = ($mass[$layer] ?? 0.0) + (float) ($e['lastfm']['play_weight'] ?? 1.0);
            }
        }
        $total = [];
        $byLayer = [];
        $usedWeight = array_sum(array_intersect_key(self::LAYER_WEIGHTS, $layers));
        foreach ($layers as $layer => $vectors) {
            foreach ($vectors as [$vec, $artistWeight]) {
                foreach ($vec as $g => $w) {
                    $share = $w * $artistWeight / $mass[$layer];
                    $byLayer[$g][$layer] = ($byLayer[$g][$layer] ?? 0) + $share;
                    $total[$g] = ($total[$g] ?? 0) + $share * self::LAYER_WEIGHTS[$layer] / $usedWeight;
                }
            }
        }
        arsort($total);

        $out = [];
        foreach ($total as $g => $w) {
            $row = ['name' => $g, 'weight' => round($w, 4)];
            if ($field === 'genres') {
                $row['family'] = TagClassifier::family($g);
            }
            $row['by_layer'] = array_map(static fn ($v) => round($v, 3), $byLayer[$g]);
            $out[] = $row;
        }
        return $out;
    }

    private static function attributes(array $entries): array
    {
        $countries = array_count_values(array_filter(array_column($entries, 'country')));
        arsort($countries);
        return ['countries' => $countries, 'danish_share' => round(($countries['DK'] ?? 0) / max(count($entries), 1), 3)];
    }

    private static function matchSummary(array $entries): array
    {
        $count = array_count_values(array_map(static fn ($e) => $e['musicbrainz']['status'], $entries));
        ksort($count);
        return $count;
    }
}
