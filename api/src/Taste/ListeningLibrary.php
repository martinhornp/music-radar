<?php

declare(strict_types=1);

namespace MusicRadar\Taste;

use MusicRadar\LastFm\LastFmClient;

/**
 * The listener's whole Last.fm artist library (every artist ever played,
 * ~11,000), built in the weekly profile refresh and stored in the database
 * (listening_artists) for the artist-interest signal.
 *
 * Per artist: name, plays all time / last 12 months / last 3 months, and
 * what is known about its identity - WITHOUT a MusicBrainz lookup per artist:
 *   profile      the same name as a profile artist whose MusicBrainz id was
 *                confirmed (ArtistMatcher) - that id is stored as 'mbid'
 *   lastfm_mbid  Last.fm suggests a MusicBrainz id - a hint only, it can
 *                point to another artist with the same name
 *   name_only    nothing but the name
 * How much each kind counts is decided where it is used (ArtistInterest).
 *
 * Cost: one user.getTopArtists request per 1,000 artists and period
 * (~17 requests, a few seconds), within Last.fm's rate limit.
 */
final class ListeningLibrary
{
    public const PERIODS = ['overall', '12month', '3month'];
    private const PAGE = 1000;
    private const MAX_PAGES = 30; // 30,000 artists - a safety stop

    public function __construct(private readonly LastFmClient $lastfm)
    {
    }

    /**
     * @param array<string, mixed>|null $profile  the music profile (its confirmed MusicBrainz ids)
     * @return list<array{name: string, name_key: string, lastfm_mbid: string, mbid: string, identity: string, rank: ?int, plays: array<string, int>}>
     */
    public function build(string $user, ?array $profile): array
    {
        $byKey = [];
        foreach (self::PERIODS as $period) {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $data = $this->lastfm->call('user.getTopArtists', ['user' => $user, 'period' => $period, 'limit' => self::PAGE, 'page' => $page]);
                $rows = $data['topartists']['artist'] ?? [];
                foreach ($rows as $i => $row) {
                    $name = trim((string) ($row['name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $k = self::key($name);
                    $a = $byKey[$k] ?? ['name' => $name, 'name_key' => $k, 'lastfm_mbid' => '', 'mbid' => '', 'identity' => 'name_only', 'rank' => null,
                        'plays' => array_fill_keys(self::PERIODS, 0)];
                    $a['plays'][$period] += (int) ($row['playcount'] ?? 0);
                    $a['lastfm_mbid'] = $a['lastfm_mbid'] ?: (string) ($row['mbid'] ?? '');
                    if ($period === 'overall') {
                        $a['rank'] ??= ($page - 1) * self::PAGE + $i + 1;
                    }
                    $byKey[$k] = $a;
                }
                $pages = (int) ($data['topartists']['@attr']['totalPages'] ?? 1);
                if ($rows === [] || $page >= $pages) {
                    break;
                }
            }
        }
        return self::identify(array_values($byKey), $profile);
    }

    /**
     * Sets the identity of each library artist (see the class comment).
     *
     * @param list<array<string, mixed>> $artists
     * @return list<array<string, mixed>>
     */
    public static function identify(array $artists, ?array $profile): array
    {
        $confirmed = [];
        foreach ($profile['artists'] ?? [] as $p) {
            $mbid = $p['musicbrainz']['mbid'] ?? null;
            if ($mbid && in_array($p['musicbrainz']['status'] ?? 'confirmed', [ArtistMatch::CONFIRMED, ArtistMatch::MANUAL], true)) {
                $confirmed[self::key((string) $p['name'])] = $mbid;
            }
        }
        foreach ($artists as &$a) {
            $a['mbid'] = $confirmed[$a['name_key']] ?? '';
            $a['identity'] = $a['mbid'] !== '' ? 'profile' : ($a['lastfm_mbid'] !== '' ? 'lastfm_mbid' : 'name_only');
        }
        unset($a);
        return $artists;
    }

    /** Name for comparing artist names: lower case, single spaces ("ALMA" = "alma", but "Ø" != "O"). */
    public static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
