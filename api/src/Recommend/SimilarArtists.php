<?php

declare(strict_types=1);

namespace MusicRadar\Recommend;

use MusicRadar\Taste\ListeningLibrary;

/**
 * Last.fm Similar Artists as links between a release's artist and the artists
 * in your music profile - one signal among others, never a verdict on sound:
 * Last.fm documents only that `match` runs from 0 (not similar) to 1 (very
 * similar), relative within each artist's list.
 *
 * Both directions count, either alone is enough, both together more:
 *   out  your profile artist in the release artist's list, times how sure we
 *        are that the list belongs to this artist (identity)
 *   in   the release artist in your profile artist's list - matched by
 *        MusicBrainz id, or by the confirmed Last.fm page name (certain), else
 *        by name only (half)
 *   link = 1 - (1 - out)(1 - in)
 *
 * Identity of a stored list: mbid (Last.fm knows the MusicBrainz id) or
 * confirmed (MusicBrainz links the artist to that Last.fm page) = certain;
 * name (found by exact name, no confirmation) = 0.5 to start with; corrected
 * (Last.fm answered with another name) and none = no own list. No data = no
 * link, never a penalty.
 *
 * A name match is more plausible when the name is connected to several
 * DIFFERENT artists of your profile (a namesake band would rarely be linked
 * to both Knocked Loose and Initiate) - but plausible is not proven: its
 * certainty rises from 0.5 to at most 0.75, never to the 1.0 of a documented
 * MusicBrainz match. Each profile artist counts once (either direction);
 * artists that appear in many lists ("hubs") count less; and the link being
 * scored never supports itself (leave-one-out), so the same link is not
 * rewarded twice - once as identity and once as music.
 */
final class SimilarArtists
{
    public const CERTAINTY = ['mbid' => 1.0, 'confirmed' => 1.0, 'name' => 0.5, 'corrected' => 0.0, 'none' => 0.0];

    /** Name match: certainty from corroborating links, 0.5 + up to 0.25 at CORROBORATION_FULL. */
    private const NAME_CERTAINTY_MAX = 0.75;
    /** Corroboration worth "full": two clear links (match 0.5) to different, non-hub artists. */
    private const CORROBORATION_FULL = 1.0;

    /** @var array<string, float>|null profile artist key => weight (hubs count less) */
    private ?array $hubWeight = null;

    /** @var array<string, array<string, float>> artist key => (m:<mbid> | n:<name key> => match) */
    private array $index = [];

    /** @param array<string, array<string, mixed>> $rows stored rows by artist key (DiscoveryStore::similar) */
    public function __construct(private readonly array $rows)
    {
        foreach ($rows as $key => $row) {
            $idx = [];
            foreach ($row['links'] as [$name, $mbid, $match]) {
                $k = 'n:' . ListeningLibrary::key((string) $name);
                $idx[$k] = max($idx[$k] ?? 0.0, (float) $match);
                if ($mbid !== '') {
                    $idx["m:$mbid"] = max($idx["m:$mbid"] ?? 0.0, (float) $match);
                }
            }
            $this->index[$key] = $idx;
        }
    }

    public static function key(?string $mbid, string $name): string
    {
        return $mbid ? "m:$mbid" : 'n:' . ListeningLibrary::key($name);
    }

    /**
     * Links from one release artist to each profile artist that has any.
     *
     * @param array{mbid?: ?string, name?: string} $artist
     * @param list<array{name: string, mbid: ?string}> $profileArtists
     * @return array{identity: string, links: array<string, array{s: float, out: float, in: float}>}
     */
    public function links(array $artist, array $profileArtists): array
    {
        $xMbid = ($artist['mbid'] ?? null) ?: null;
        $xName = (string) ($artist['name'] ?? '');
        $own = $this->rows[self::key($xMbid, $xName)] ?? null;
        $identity = $own['identity'] ?? 'none';
        $cert = self::CERTAINTY[$identity] ?? 0.0;
        $ownIndex = $cert > 0 ? ($this->index[self::key($xMbid, $xName)] ?? []) : [];
        $page = $cert >= 1.0 ? ListeningLibrary::key((string) ($own['lastfm_page'] ?? '')) : null;
        $xKey = ListeningLibrary::key($xName);

        // Raw matches per profile artist: from the release artist's own list,
        // and the release artist inside the profile artist's list (by id or
        // confirmed page = certain; by name = needs identity certainty).
        $raw = [];
        foreach ($profileArtists as $p) {
            $pk = self::key($p['mbid'] ?? null, $p['name']);
            $o = max($p['mbid'] ? ($ownIndex['m:' . $p['mbid']] ?? 0.0) : 0.0, $ownIndex['n:' . ListeningLibrary::key($p['name'])] ?? 0.0);
            $idx = $this->index[$pk] ?? [];
            $sure = max($xMbid ? ($idx["m:$xMbid"] ?? 0.0) : 0.0, $page !== null ? ($idx["n:$page"] ?? 0.0) : 0.0);
            $byName = $idx["n:$xKey"] ?? 0.0;
            if ($o > 0 || $sure > 0 || $byName > 0) {
                $raw[$p['name']] = ['key' => $pk, 'out' => $o, 'sure' => $sure, 'name' => $byName];
            }
        }
        // Name only: certainty from the OTHER profile artists the name is linked to.
        $support = [];
        if ($cert < 1.0) {
            $hub = $this->hubWeights($profileArtists);
            foreach ($raw as $n => $x) {
                $support[$n] = ($hub[$x['key']] ?? 1.0) * max($cert > 0 ? $x['out'] : 0.0, $x['name']);
            }
        }
        $total = array_sum($support);
        $out = [];
        $best = $cert;
        foreach ($raw as $n => $x) {
            $c = $cert >= 1.0 ? 1.0
                : 0.5 + (self::NAME_CERTAINTY_MAX - 0.5) * min(1.0, ($total - ($support[$n] ?? 0.0)) / self::CORROBORATION_FULL);
            $best = max($best, $c);
            $o = ($cert > 0 ? $c : 0.0) * $x['out'];
            $in = max($x['sure'], ($cert >= 1.0 ? 0.5 : $c) * $x['name']);
            if ($o > 0 || $in > 0) {
                $out[$n] = ['s' => round(1 - (1 - $o) * (1 - $in), 4), 'out' => round($o, 3), 'in' => round($in, 3)];
            }
        }
        return ['identity' => $identity, 'certainty' => round($best, 3), 'links' => $out];
    }

    /**
     * How much a link to each profile artist can support an identity: artists
     * in many release artists' lists (hubs) say less. ln(1 + N/(1 + n)) / ln(1 + N),
     * N = release artists' lists, n = lists that contain the profile artist.
     *
     * @param list<array{name: string, mbid: ?string}> $profileArtists
     * @return array<string, float> by profile artist key
     */
    private function hubWeights(array $profileArtists): array
    {
        if ($this->hubWeight !== null) {
            return $this->hubWeight;
        }
        $lists = array_filter($this->rows, static fn ($r) => ($r['role'] ?? '') === 'release');
        $n = max(1, count($lists));
        $this->hubWeight = [];
        foreach ($profileArtists as $p) {
            $count = 0;
            foreach (array_keys($lists) as $k) {
                $idx = $this->index[$k];
                if (($p['mbid'] && isset($idx['m:' . $p['mbid']])) || isset($idx['n:' . ListeningLibrary::key($p['name'])])) {
                    $count++;
                }
            }
            $this->hubWeight[self::key($p['mbid'] ?? null, $p['name'])] = round(log(1 + $n / (1 + $count)) / log(1 + $n), 3);
        }
        return $this->hubWeight;
    }
}
