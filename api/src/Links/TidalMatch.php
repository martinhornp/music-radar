<?php

declare(strict_types=1);

namespace MusicRadar\Links;

final class TidalMatch
{
    public static function albumId(string $url): ?string
    {
        return preg_match('#^https://(?:listen\.)?tidal\.com/(?:browse/)?album/([1-9][0-9]*)/?$#D', $url, $m) ? $m[1] : null;
    }

    /** Change only when the actual identity requirements change incompatibly. */
    public static function validationHash(): string
    {
        // v4: v3 + albums only for a country outside Europe are dropped; hi-res ranks last
        return hash('sha256', 'musicbrainz-official-digital-v4');
    }

    public static function identityHash(array $release, array $credits): string
    {
        $artists = array_column($credits ?: [['mbid' => $release['artist_mbid'] ?? '']], 'mbid');
        sort($artists);
        return hash('sha256', json_encode([self::title($release['title']), $release['type'] ?? 'Album',
            $release['first_release_date'], $artists], JSON_THROW_ON_ERROR));
    }

    /** Validate a unique concrete digital edition automatically. */
    public static function assess(array $release, array $credits, array $response): array
    {
        $editions = $response['releases'] ?? [];
        if (!isset($response['release-count']) || count($editions) !== (int) $response['release-count']) {
            return ['status' => 'ambiguous', 'reason' => 'incomplete']; // Never choose from an incomplete page.
        }
        $candidates = [];
        foreach ($editions as $edition) {
            $formats = array_map(static fn ($m) => $m['format'] ?? '', $edition['media'] ?? []);
            if (($edition['status'] ?? '') !== 'Official' || $formats === [] || array_unique($formats) !== ['Digital Media']) {
                continue;
            }
            foreach ($edition['relations'] ?? [] as $rel) {
                $url = (string) ($rel['url']['resource'] ?? '');
                $host = parse_url($url, PHP_URL_HOST);
                if (!in_array($host, ['tidal.com', 'listen.tidal.com'], true)) {
                    continue;
                }
                $album = self::albumId($url);
                if ($album === null || ($rel['type'] ?? '') !== 'streaming') {
                    return ['status' => 'ambiguous'];
                }
                $candidates[$album][] = $edition;
            }
        }
        if ($candidates === []) {
            return ['status' => 'missing'];
        }
        if (count($candidates) > 1) {
            $candidates = self::choose($candidates, $release, $editions);
            if ($candidates === null) {
                return ['status' => 'ambiguous'];
            }
        }
        $album = (string) array_key_first($candidates);
        $expected = array_column($credits, 'mbid');
        sort($expected);
        if ($expected === [] || in_array('', $expected, true)) {
            return ['status' => 'ambiguous'];
        }
        $tracks = [];
        $barcodes = [];
        foreach ($candidates[$album] as $edition) {
            $artists = array_map(static fn ($c) => $c['artist']['id'] ?? '', $edition['artist-credit'] ?? []);
            sort($artists);
            $tracks[] = array_sum(array_column($edition['media'] ?? [], 'track-count'));
            if (($edition['barcode'] ?? '') !== '') {
                $barcodes[] = $edition['barcode'];
            }
            // The date and the edition's own note ("Streaming Release", "acoustic") do not
            // decide: they are the album's only TIDAL link, or were chosen in choose().
            if (empty($edition['id']) || $artists !== $expected || self::title($edition['title'] ?? '') !== self::title($release['title'])
                || array_sum(array_column($edition['media'] ?? [], 'track-count')) < 1) {
                return ['status' => 'ambiguous'];
            }
        }
        if (count(array_unique($tracks)) !== 1 || count(array_unique($barcodes)) > 1) {
            return ['status' => 'ambiguous']; // Conflicting editions cannot identify one album safely.
        }
        // A note meaning other music (acoustic, live ...) is only the album itself when
        // every edition of the release carries it - otherwise it is another version.
        foreach ($candidates[$album] as $edition) {
            if (preg_match(self::OTHER_MUSIC, (string) ($edition['disambiguation'] ?? ''), $m)) {
                foreach ($editions as $other) {
                    if (stripos((string) ($other['disambiguation'] ?? ''), $m[1]) === false) {
                        return ['status' => 'ambiguous'];
                    }
                }
            }
        }
        $ids = array_column($candidates[$album], 'id');
        sort($ids);
        return ['status' => 'verified', 'album' => $album, 'edition' => $ids[0]];
    }

    /**
     * Several TIDAL albums for one release, in this order - otherwise never a guess (null):
     *  1. only albums whose editions carry the release's own title ("(Deluxe)" is another album);
     *  2. explicit, then the unmarked main edition, then clean, then Dolby Atmos;
     *  3. of several unmarked albums from different labels: the one whose label also
     *     releases the record in Europe (the US and EU licences are separate albums).
     *
     * @param array<string, list<array>> $candidates TIDAL album id => its editions
     * @return array<string, list<array>>|null one album
     */
    private static function choose(array $candidates, array $release, array $editions): ?array
    {
        $titled = array_filter($candidates, static fn ($versions) => array_filter($versions,
            static fn ($e) => self::title($e['title'] ?? '') !== self::title($release['title'])) === []);
        if ($titled === []) {
            return null;
        }
        if (count($titled) === 1) {
            return $titled;
        }
        // An album released only for one country outside Europe (JP, CA, US ...) is not
        // the one for a listener here - unless every album is such a release.
        $here = array_filter($titled, static fn ($versions) => array_filter($versions,
            static fn ($e) => !self::outsideEurope((string) ($e['country'] ?? ''))) !== []);
        if ($here !== []) {
            $titled = $here;
        }
        if (count($titled) === 1) {
            return $titled;
        }
        $ranks = [];
        foreach ($titled as $id => $versions) {
            $rank = array_unique(array_map(static fn ($e) => self::versionRank((string) ($e['disambiguation'] ?? '')), $versions));
            if (count($rank) !== 1 || $rank[0] === null) {
                return null;
            }
            $ranks[$id] = $rank[0];
        }
        asort($ranks);
        $best = array_keys($ranks, reset($ranks), true);
        if (count($best) === 1) {
            return [$best[0] => $titled[$best[0]]];
        }
        // Same kind: tell them apart by the label that releases the record in Europe.
        $european = [];
        foreach ($editions as $e) {
            if (in_array($e['country'] ?? '', self::EUROPE, true)) {
                foreach (self::labels($e) as $label) {
                    $european[$label] = true;
                }
            }
        }
        $inEurope = array_values(array_filter($best, static fn ($id) => array_intersect_key(
            array_flip(array_merge(...array_map(self::labels(...), $titled[$id]))), $european) !== []));
        $labels = array_map(static fn ($id) => array_merge(...array_map(self::labels(...), $titled[$id])), $best);
        if (count($inEurope) !== 1 || count(array_unique(array_map(static fn ($l) => implode('|', $l), $labels))) !== count($best)) {
            return null;
        }
        return [$inEurope[0] => $titled[$inEurope[0]]];
    }

    /** Edition notes that mean other music, not another way to sell the same album. */
    private const OTHER_MUSIC = '/\b(acoustic|live|instrumental|remix|remixes|demo|demos|unplugged|karaoke|deluxe|expanded|a cappella|acapella|stripped|orchestral)\b/i';

    /** MusicBrainz release countries in Europe (XE = Europe as a whole). */
    private const EUROPE = ['XE', 'AD', 'AL', 'AT', 'BA', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FO', 'FR', 'GB',
        'GR', 'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MC', 'MD', 'ME', 'MK', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO',
        'RS', 'SE', 'SI', 'SK', 'SM', 'UA', 'VA'];

    /** A specific country outside Europe; worldwide (XW) or unknown is not. */
    private static function outsideEurope(string $country): bool
    {
        return $country !== '' && $country !== 'XW' && !in_array($country, self::EUROPE, true);
    }

    /** @return list<string> */
    private static function labels(array $edition): array
    {
        return array_values(array_unique(array_filter(array_map(static fn ($l) => (string) ($l['label']['name'] ?? ''), $edition['label-info'] ?? []))));
    }

    /** Known versions of one album, most preferred first; null = any other edition. */
    private static function versionRank(string $disambiguation): ?int
    {
        // Hi-res / lossless store versions ("hi-res", "Qobuz : 24-Bit/44.1 kHz") come last.
        if (preg_match('/hi[\s\-]?res|24[\s\-\x{2010}\x{2011}]?bit|qobuz|mqa/iu', $disambiguation)) {
            return 4;
        }
        return match (mb_strtolower(trim($disambiguation))) {
            'explicit' => 0,
            '' => 1,
            'clean' => 2,
            'dolby atmos', 'dolby atmos mix' => 3,
            default => null,
        };
    }

    private static function title(string $value): string
    {
        // Typographic punctuation may differ; edition words are never removed.
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($value));
    }
}
