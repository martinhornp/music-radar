<?php

declare(strict_types=1);

namespace MusicRadar\Recommend;

use MusicRadar\Taste\ListeningLibrary;

/**
 * How interested the listener is in the artists credited on a release - a
 * signal of its own, kept apart from the music score (fit):
 *
 *   following   an artist you follow (profile) - always recommended
 *   interested  an artist from your profile (the ~66) - always recommended
 *   listened    an artist from your Last.fm library - may lift a release whose
 *               music is a near miss, never guarantees anything
 *   unknown     none of the credited artists
 *
 * Every credited artist counts ("A & B", "A feat. B", 3+ artists): the
 * strongest watch level wins, and the listening values are combined so that
 * more played artists mean more interest (1 - (1-a)(1-b)...).
 *
 * The listening value of one artist (0..1) is gradual - no "10 plays and
 * you're in": each period's plays on a log scale up to a reference amount,
 * recent periods weighted most (config 'interest' in recommender.php), shrunk
 * toward zero while there are only a few plays (n / (n + plays_prior)), times
 * how sure we are that it is the same artist:
 *   confirmed  same MusicBrainz id as a confirmed profile artist, or Last.fm's
 *              id hint AND the name agree
 *   mbid_only  Last.fm's id hint agrees, the name differs (renamed, alias)
 *   name_only  only the name agrees, Last.fm has no id - could be a namesake
 *   conflict   the name agrees but Last.fm points to another MusicBrainz
 *              artist - a namesake (e.g. two artists called "ALMA"): no interest
 * Artists you rated low (average at most 'disliked_max_stars') get no interest:
 * your ratings say more than old plays.
 */
final class ArtistInterest
{
    public const DEFAULTS = [
        'enabled' => true,
        'periods' => [
            'overall' => ['weight' => 0.20, 'ref' => 150],
            '12month' => ['weight' => 0.45, 'ref' => 40],
            '3month' => ['weight' => 0.35, 'ref' => 12],
        ],
        'certainty' => ['confirmed' => 1.0, 'mbid_only' => 0.8, 'name_only' => 0.5, 'conflict' => 0.0],
        'plays_prior' => 3,
        'listened_min' => 0.10,
        'disliked_max_stars' => 2.0,
    ];
    private const ORDER = ['following' => 2, 'interested' => 1];

    /** @var array<string, array{level: string, name: string}> profile artists by MusicBrainz id */
    private array $watched = [];
    /** @var array<string, array<string, mixed>> library rows by confirmed MusicBrainz id */
    private array $byMbid = [];
    /** @var array<string, array<string, mixed>> library rows by Last.fm's MusicBrainz id hint */
    private array $byHint = [];
    /** @var array<string, array<string, mixed>> library rows by name key */
    private array $byName = [];
    /** @var array<string, float> average stars per artist MusicBrainz id */
    private array $stars = [];
    private array $cfg;

    /**
     * @param array<string, mixed> $profile  the music profile (watch levels)
     * @param list<array<string, mixed>> $library  DiscoveryStore::listening()
     * @param list<array{artist_mbid?: ?string, stars: int}> $ratings  your star ratings
     */
    public function __construct(array $profile, array $library = [], array $config = [], array $ratings = [])
    {
        $this->cfg = array_replace_recursive(self::DEFAULTS, $config);
        foreach ($profile['artists'] ?? [] as $a) {
            $mbid = $a['musicbrainz']['mbid'] ?? null;
            $level = $a['watch']['level'] ?? null;
            if ($mbid && isset(self::ORDER[$level])) {
                $this->watched[$mbid] = ['level' => $level, 'name' => (string) $a['name']];
            }
        }
        if ($this->cfg['enabled']) {
            foreach ($library as $row) {
                if ($row['mbid'] !== '') {
                    $this->byMbid[$row['mbid']] ??= $row;
                }
                if ($row['lastfm_mbid'] !== '') {
                    $this->byHint[$row['lastfm_mbid']] ??= $row;
                }
                $this->byName[$row['name_key']] ??= $row;
            }
        }
        $sum = [];
        foreach ($ratings as $r) {
            if (($r['artist_mbid'] ?? null) && $r['stars'] >= 1 && $r['stars'] <= 5) {
                $sum[$r['artist_mbid']][] = (int) $r['stars'];
            }
        }
        $this->stars = array_map(static fn ($s) => array_sum($s) / count($s), $sum);
    }

    /**
     * The strongest watch level among the credited artists, and which artist it is.
     *
     * @param list<array{mbid: string, name?: string}> $credits
     * @return array{level: string, mbid: string, name: string}|null
     */
    public function watch(array $credits): ?array
    {
        $best = null;
        foreach ($credits as $c) {
            $w = $this->watched[$c['mbid']] ?? null;
            if ($w !== null && ($best === null || self::ORDER[$w['level']] > self::ORDER[$best['level']])) {
                $best = $w + ['mbid' => $c['mbid']];
            }
        }
        return $best;
    }

    /**
     * Interest in a release from all its credited artists.
     *
     * @param list<array{mbid: string, name: string, artist_name: string}> $credits
     * @return array{level: string, value: float, watch: ?array, artists: list<array<string, mixed>>}
     */
    public function forRelease(array $credits): array
    {
        $artists = [];
        $none = 1.0;
        foreach ($credits as $c) {
            $m = $this->listened($c);
            if ($m !== null) {
                $artists[] = $m;
                $none *= 1 - $m['value'];
            }
        }
        $value = round(1 - $none, 3);
        $watch = $this->watch($credits);
        $level = $watch['level'] ?? ($value >= $this->cfg['listened_min'] ? 'listened' : 'unknown');
        return ['level' => $level, 'value' => $value, 'watch' => $watch, 'artists' => $artists];
    }

    /**
     * Listening value of one library artist from its plays alone (0..1),
     * before identity certainty and your ratings: plays per period on a log
     * scale, recent periods weighted most, shrunk while there are few plays.
     *
     * @param array<string, int> $plays  overall / 12month / 3month
     */
    public function playsValue(array $plays): float
    {
        $value = 0.0;
        foreach ($this->cfg['periods'] as $period => $p) {
            $n = (int) ($plays[$period] ?? 0);
            $value += $p['weight'] * min(1.0, log(1 + $n) / log(1 + $p['ref']));
        }
        // A few plays are thin evidence (an autoplay, a single track): shrunk
        // toward zero like a rating, fading out as plays add up.
        $total = max(array_map('intval', $plays) ?: [0]);
        return $value * ($total > 0 ? $total / ($total + (float) $this->cfg['plays_prior']) : 0.0);
    }

    /** The value from which a release counts as "listened" (config listened_min). */
    public function listenedMin(): float
    {
        return (float) $this->cfg['listened_min'];
    }

    /** One credited artist in the Last.fm library, or null. */
    private function listened(array $c): ?array
    {
        $names = array_unique([ListeningLibrary::key($c['artist_name'] ?? ''), ListeningLibrary::key($c['name'] ?? '')]);
        [$row, $match] = match (true) {
            isset($this->byMbid[$c['mbid']]) => [$this->byMbid[$c['mbid']], 'confirmed'],
            isset($this->byHint[$c['mbid']]) => [$this->byHint[$c['mbid']], in_array($this->byHint[$c['mbid']]['name_key'], $names, true) ? 'confirmed' : 'mbid_only'],
            default => [null, null],
        };
        if ($row === null) {
            foreach ($names as $k) {
                if (isset($this->byName[$k])) {
                    $row = $this->byName[$k];
                    $other = $row['mbid'] !== '' ? $row['mbid'] : $row['lastfm_mbid'];
                    $match = $other === '' ? 'name_only' : ($other === $c['mbid'] ? 'confirmed' : 'conflict');
                    break;
                }
            }
        }
        if ($row === null) {
            return null;
        }
        $plays = $this->playsValue($row['plays']);
        $certainty = (float) $this->cfg['certainty'][$match];
        $stars = $this->stars[$c['mbid']] ?? null;
        $disliked = $stars !== null && $stars <= $this->cfg['disliked_max_stars'];
        return [
            'mbid' => $c['mbid'],
            'name' => $c['name'] ?: $row['name'],
            'lastfm_name' => $row['name'],
            'match' => $match,
            'plays' => $row['plays'],
            'value' => $disliked ? 0.0 : round($plays * $certainty, 3),
            'disliked' => $disliked,
        ];
    }

    /**
     * "You have played X on Last.fm (recently)" - only facts, and no play
     * counts (the site is public; listening counts stay private, like in the
     * rest of the feed). An uncertain match says so ('certain' => false).
     *
     * @return array{text: string, certain: bool}|null
     */
    public static function reason(array $interest): ?array
    {
        $sure = [];
        $unsure = [];
        foreach ($interest['artists'] ?? [] as $a) {
            if ($a['value'] <= 0) {
                continue;
            }
            $when = $a['plays']['3month'] > 0 ? 'recently' : ($a['plays']['12month'] > 0 ? 'this past year' : 'earlier');
            $text = sprintf('%s (%s%s)', $a['name'], $a['match'] === 'mbid_only' ? "as {$a['lastfm_name']}, " : '', $when);
            if ($a['match'] !== 'name_only') {
                $sure[] = $text;
            } else {
                $unsure[] = $text;
            }
        }
        if ($sure !== []) {
            return ['text' => 'You have played ' . implode(' and ', $sure) . ' on Last.fm', 'certain' => true];
        }
        return $unsure !== [] ? ['text' => 'Possibly an artist you have played on Last.fm: ' . implode(' and ', $unsure) . ' - matched by name only', 'certain' => false] : null;
    }
}
