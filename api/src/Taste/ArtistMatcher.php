<?php

declare(strict_types=1);

namespace MusicRadar\Taste;

/**
 * Decides which MusicBrainz artist (if any) a Last.fm artist is.
 * Pure logic - no network - so the rules can be tested with fixtures.
 *
 * Principle: rather "needs review" than a wrong artist.
 *
 * Evidence per candidate with exactly the same name (or alias):
 *   +2  Last.fm's own MusicBrainz ID points to it
 *   +2  its genre tags share a family with the Last.fm tags
 *   +1  its country matches a nationality tag on Last.fm
 *   -3  genres clearly disagree        -2  country clearly disagrees
 *
 * Confirm only when the name matches AND there is positive evidence AND
 * nothing contradicts it AND no other same-name artist is also plausible.
 * Last.fm's MBID alone is never trusted: for "SYL" it points to Strapping
 * Young Lad.
 */
final class ArtistMatcher
{
    private const TRANSLIT = [
        'æ' => 'ae', 'ø' => 'o', 'å' => 'a', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'é' => 'e', 'è' => 'e',
        'ê' => 'e', 'á' => 'a', 'à' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        '&' => 'and',
    ];

    /** Families close enough that a mismatch between them is not a contradiction. */
    private const RELATED = [
        'Hardcore' => ['Punk', 'Post-hardcore & emo', 'Metalcore', 'Metal'],
        'Punk' => ['Hardcore', 'Post-hardcore & emo', 'Rock & indie'],
        'Post-hardcore & emo' => ['Hardcore', 'Punk', 'Metalcore', 'Rock & indie'],
        'Metalcore' => ['Hardcore', 'Metal', 'Post-hardcore & emo'],
        'Metal' => ['Metalcore', 'Hardcore', 'Rock & indie'],
        'Rock & indie' => ['Punk', 'Metal', 'Pop', 'Folk & singer-songwriter', 'Post-hardcore & emo'],
        'Pop' => ['Electronic', 'R&B & soul', 'Rock & indie', 'Folk & singer-songwriter', 'Hip hop'],
        'Electronic' => ['Pop', 'Hip hop'],
        'Hip hop' => ['R&B & soul', 'Electronic', 'Pop'],
        'R&B & soul' => ['Hip hop', 'Pop'],
        'Folk & singer-songwriter' => ['Rock & indie', 'Pop'],
        'Latin & reggaeton' => ['Pop', 'Hip hop', 'Electronic'],
    ];

    public static function normalize(string $name): string
    {
        $s = strtr(mb_strtolower(trim($name)), self::TRANSLIT);
        $s = (string) preg_replace('/^the\s+/u', '', $s);
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /**
     * @param list<string> $lastfmFamilies   genre families from the artist's Last.fm tags
     * @param list<string> $lastfmCountries  ISO codes from Last.fm nationality tags
     * @param bool $lastfmTagsSuspect        Last.fm tags look like several artists mixed together
     * @param list<array<string, mixed>> $searchResults  MusicBrainz artist search results
     * @param array<string, mixed>|null $lastfmMbidRecord  MusicBrainz record for Last.fm's MBID (if any)
     * @param list<string> $profileFamilies  the listener's families, strongest first (only to rank candidates for review)
     */
    public static function match(
        string $name,
        string $lastfmMbid,
        array $lastfmFamilies,
        array $lastfmCountries,
        bool $lastfmTagsSuspect,
        array $searchResults,
        ?array $lastfmMbidRecord,
        array $profileFamilies = [],
    ): ArtistMatch {
        $target = self::normalize($name);
        $candidates = array_map(self::candidate(...), $searchResults);
        if ($lastfmMbidRecord !== null && !in_array($lastfmMbidRecord['id'] ?? '', array_column($candidates, 'mbid'), true)) {
            $candidates[] = self::candidate($lastfmMbidRecord);
        }

        $byName = array_values(array_filter($candidates, static fn (array $c) => $c['names'][0] === $target));
        $byAlias = array_values(array_filter(
            $candidates,
            static fn (array $c) => $c['names'][0] !== $target && in_array($target, $c['names'], true),
        ));
        $problems = [];

        // An artist that only has this name as an alias (SYL -> Strapping Young
        // Lad) never beats an artist actually called this.
        $exact = $byName !== [] ? $byName : $byAlias;
        foreach ($byName !== [] ? $byAlias : [] as $alias) {
            $problems[] = "'{$alias['name']}' uses this name as an alias - not considered, since an artist with exactly this name exists";
        }
        if ($byName === [] && $byAlias !== []) {
            $problems[] = 'only matches an alias, not the artist name';
        }

        // Last.fm points to an artist we are not considering - ignore it, but say so.
        if ($lastfmMbid !== '' && !in_array($lastfmMbid, array_column($exact, 'mbid'), true)) {
            $other = $lastfmMbidRecord['name'] ?? 'an unknown artist';
            $problems[] = "Last.fm's MusicBrainz ID points to '$other' - not used";
        }
        if ($lastfmTagsSuspect) {
            $problems[] = 'Last.fm tags look like several artists mixed together - not used as evidence';
        }

        if ($exact === []) {
            $near = array_values(array_filter($candidates, static fn (array $c) => $c['score'] >= 90));
            return new ArtistMatch(
                $near ? ArtistMatch::NEEDS_REVIEW : ArtistMatch::NOT_FOUND,
                null,
                'none',
                'musicbrainz_search',
                [],
                [...$problems, $near ? 'no artist with exactly this name; similar names found' : 'no artist with this name on MusicBrainz'],
                array_map(self::publicCandidate(...), array_slice($near, 0, 5)),
            );
        }

        foreach ($exact as &$c) {
            [$c['points'], $c['evidence'], $c['conflicts'], $c['genre']] = self::evaluate(
                $c, $lastfmMbid, $lastfmFamilies, $lastfmCountries, $lastfmTagsSuspect,
            );
            if ($byName === []) {
                $c['evidence'][0] = 'name matches an alias';
            }
            $c['fits_profile'] = array_values(array_intersect($c['families'], $profileFamilies));
            // Profile families are ordered strongest first; earlier = heavier.
            $c['fit_weight'] = array_sum(array_map(
                static fn ($f) => count($profileFamilies) - (int) array_search($f, $profileFamilies, true),
                $c['fits_profile'],
            ));
        }
        unset($c);
        usort($exact, static fn ($a, $b) => [$b['points'], $b['fit_weight']] <=> [$a['points'], $a['fit_weight']]);

        $best = $exact[0];
        $others = array_slice($exact, 1);
        $lastfmElsewhere = $lastfmMbid !== '' && $lastfmMbid !== $best['mbid'];
        // With several same-name artists, the genres must single out one:
        // the best agrees, no other one agrees or is even related.
        $othersHaveGenreSupport = array_filter($others, static fn ($c) => in_array($c['genre'], ['agree', 'related'], true)) !== [];

        $confirmed = match (true) {
            $best['conflicts'] !== [] || $lastfmElsewhere => false,
            $byName === [] => false, // alias-only matches always need a human
            count($exact) === 1 => $best['points'] >= 1,
            default => !$lastfmTagsSuspect && $best['genre'] === 'agree' && !$othersHaveGenreSupport
                && ($others[0]['points'] ?? 0) <= 1,
        };

        if (count($exact) > 1) {
            $problems[] = count($exact) . ' artists on MusicBrainz share this name';
        }
        if (!$confirmed && $best['points'] < 1 && $best['conflicts'] === []) {
            $problems[] = 'only the name matches - no other data to confirm it';
        }
        $problems = [...$problems, ...$best['conflicts']];

        if ($confirmed) {
            return new ArtistMatch(
                ArtistMatch::CONFIRMED,
                $best['mbid'],
                $best['points'] >= 3 ? 'high' : 'medium',
                'musicbrainz_search',
                $best['evidence'],
                $problems,
                [],
                $best['name'],
                $best['country'],
                $best['disambiguation'],
                $best['tags'],
            );
        }

        return new ArtistMatch(
            ArtistMatch::NEEDS_REVIEW,
            null,
            'low',
            'musicbrainz_search',
            [],
            $problems,
            array_map(self::publicCandidate(...), array_slice([...$exact, ...($byName !== [] ? $byAlias : [])], 0, 6)),
        );
    }

    /**
     * @return array{int, list<string>, list<string>, string}
     *   points, evidence, conflicts, genre verdict (agree|related|conflict|unknown)
     */
    private static function evaluate(array $c, string $lastfmMbid, array $lfFamilies, array $lfCountries, bool $suspect): array
    {
        $points = 0;
        $evidence = ['name matches exactly'];
        $conflicts = [];
        $genre = 'unknown';

        if ($lastfmMbid !== '' && $c['mbid'] === $lastfmMbid) {
            $points += 2;
            $evidence[] = "Last.fm links to this MusicBrainz ID";
        }
        if (!$suspect && $lfFamilies !== [] && $c['families'] !== []) {
            $shared = array_values(array_intersect($c['families'], $lfFamilies));
            $related = array_values(array_filter(
                $c['families'],
                static fn ($f) => array_intersect(self::RELATED[$f] ?? [], $lfFamilies) !== [],
            ));
            if ($shared !== []) {
                $points += 2;
                $genre = 'agree';
                $evidence[] = 'genres agree (' . implode(', ', $shared) . ')';
            } elseif ($related !== []) {
                $points += 1;
                $genre = 'related';
                $evidence[] = 'related genres (' . implode(', ', $related) . ')';
            } else {
                $points -= 3;
                $genre = 'conflict';
                $conflicts[] = 'genres disagree: MusicBrainz says ' . implode(', ', $c['families'])
                    . ', Last.fm says ' . implode(', ', $lfFamilies);
            }
        }
        if (!$suspect && $lfCountries !== [] && $c['country'] !== null) {
            if (in_array($c['country'], $lfCountries, true)) {
                $points += 1;
                $evidence[] = "country agrees ({$c['country']})";
            } else {
                $points -= 2;
                $conflicts[] = "country disagrees: MusicBrainz {$c['country']}, Last.fm " . implode('/', $lfCountries);
            }
        }

        return [$points, $evidence, $conflicts, $genre];
    }

    /** Normalises a MusicBrainz search/lookup record. */
    private static function candidate(array $r): array
    {
        $names = [self::normalize((string) ($r['name'] ?? ''))];
        foreach ($r['aliases'] ?? [] as $alias) {
            $names[] = self::normalize((string) ($alias['name'] ?? ''));
        }
        $tags = array_map(static fn ($t) => (string) $t['name'], [...($r['genres'] ?? []), ...($r['tags'] ?? [])]);

        return [
            'mbid' => (string) ($r['id'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'names' => array_values(array_unique(array_filter($names))),
            'score' => (int) ($r['score'] ?? 0),
            'type' => $r['type'] ?? null,
            'country' => $r['country'] ?? ($r['area']['iso-3166-1-codes'][0] ?? null),
            'disambiguation' => ($r['disambiguation'] ?? '') ?: null,
            'tags' => array_values(array_unique($tags)),
            'families' => TagClassifier::familiesOf($tags),
        ];
    }

    private static function publicCandidate(array $c): array
    {
        return array_filter([
            'mbid' => $c['mbid'],
            'name' => $c['name'],
            'country' => $c['country'],
            'type' => $c['type'],
            'disambiguation' => $c['disambiguation'],
            'tags' => array_slice($c['tags'], 0, 6),
            'evidence' => $c['evidence'] ?? null,
            'conflicts' => $c['conflicts'] ?? null,
            'fits_your_profile' => $c['fits_profile'] ?? null,
        ], static fn ($v) => $v !== null && $v !== []);
    }
}
