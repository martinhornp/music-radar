<?php

declare(strict_types=1);

namespace MusicRadar\Taste;

/**
 * Turns raw MusicBrainz and Last.fm tags into usable information using fixed,
 * documented rules - no guessing. A tag is either:
 *
 *   genre     a musical style: on MusicBrainz' genre list, an extra genre, or
 *             built from a genre word ("metallic hardcore", "synthpunk")
 *   property  a documented musical property ("aggressive", "harsh vocals") -
 *             kept apart from genres, not used in the score
 *   meta      true but not musical: nationality, decade, label ("danish", "90s")
 *   noise     says nothing about the music ("seen live", "offizielle charts",
 *             "animal on cover", "i have this album")
 *   unknown   anything else - kept in the source data, never counted as a genre
 *
 * The lists live in api/data/tag-classes.php and api/data/musicbrainz-genres.txt.
 * Variants are merged by a canonical key ("hip-hop" = "hip hop" = "hiphop",
 * "neo-soul" = "neo soul"), preferring MusicBrainz' spelling. Every genre is
 * also placed in a broad family by keyword rules (see FAMILIES).
 */
final class TagClassifier
{
    /** Ignore tags Last.fm itself weights below this (0-100) - too few taggers. */
    public const MIN_COUNT = 10;

    /** canonical key => preferred display name */
    private const ALIASES = [
        'hiphop' => 'hip hop',
        'rapmusic' => 'rap',
        'danskhiphop' => 'danish hip hop',
        'danskrap' => 'danish rap',
        'poppunk' => 'pop punk',
        'posthardcore' => 'post-hardcore',
        'postpunk' => 'post-punk',
        'numetal' => 'nu metal',
        'rnb' => 'r&b',
        'randb' => 'r&b',
        'rb' => 'r&b',
        'drumandbass' => 'drum and bass',
        'drumnbass' => 'drum and bass',
        'dnb' => 'drum and bass',
        'electronica' => 'electronica',
        'singersongwriter' => 'singer-songwriter',
        'altrock' => 'alternative rock',
        'alternativerock' => 'alternative rock',
        'alternative' => 'alternative',
        'nyhc' => 'new york hardcore',
        'jpop' => 'j-pop',
        'kpop' => 'k-pop',
        'turntablists' => 'turntablism',
        'turntablist' => 'turntablism',
        // Short genre names the "fragment" rule would otherwise discard.
        'oi' => 'oi',
        'hc' => 'hardcore',
    ];

    private const NOISE = [
        'seenlive', 'favorites', 'favourites', 'favorite', 'favourite', 'favoriteartists', 'favouriteartists',
        'myfavorite', 'myfavourite', 'love', 'loved', 'awesome', 'amazing', 'beautiful', 'cool', 'best', 'great',
        'genius', 'legend', 'legends', 'sexy', 'cute', 'fun', 'spotify', 'albumsiown', 'mustsee', 'underrated',
        'overrated', 'check out', 'checkout', 'todo', 'all', 'music', 'good', 'other', 'misc', 'favoritebands',
        'favouritebands', 'iloveit', 'perfection', 'queen', 'king', 'goat', 'icon', 'idol', 'hot',
        'my top songs', 'mytopsongs', 'top songs', 'trollerier', 'beste', 'elsker',
    ];

    private const META = [
        'british', 'english', 'uk', 'american', 'usa', 'us', 'danish', 'dansk', 'denmark', 'danmark',
        'australian', 'australia', 'canadian', 'canada', 'swedish', 'sweden', 'irish', 'ireland', 'german', 'germany',
        'norwegian', 'norway', 'french', 'france', 'belgian', 'belgium', 'dutch', 'scottish', 'welsh', 'finnish',
        'newzealand', 'californian', 'california', 'newyork', 'london', 'copenhagen', 'kobenhavn', 'københavn',
        'under2000listeners', 'underground', 'indie label', 'diy', 'band', 'solo', 'producer', 'dj', 'duo',
        'supergroup', 'singer', 'rapper', 'composer',
        // Nationality / language - not genres
        'japanese', 'japan', 'chinese', 'china', 'korean', 'korea', 'argentina', 'argentinian', 'brazilian',
        'brazil', 'spanish', 'spain', 'italian', 'italy', 'mexican', 'mexico', 'polish', 'icelandic', 'faroese',
        'russian', 'russia', 'ukrainian', 'ukraine', 'portuguese', 'portugal', 'greek', 'turkish', 'czech',
        'hungarian', 'romanian', 'austrian', 'swiss', 'indian', 'india', 'chilean', 'colombian', 'peruvian',
        'indonesian', 'filipino', 'thai', 'vietnamese', 'israeli', 'african', 'nigerian', 'southafrican',
        'european', 'scandinavian', 'nordic', 'latino', 'asian', 'estonian', 'latvian', 'lithuanian', 'serbian',
        'croatian', 'slovenian', 'slovak', 'bulgarian',
        // Themes / scenes - not musical styles
        'political', 'straight edge', 'straightedge', 'christian', 'lgbt', 'queer', 'feminist', 'antifascist',
    ];

    /** Cities/regions - where an artist is from, not how it sounds (keys). */
    private const PLACES = [
        'montreal', 'quebec', 'toronto', 'vancouver', 'losangeles', 'newyorkcity', 'nyc', 'brooklyn', 'chicago',
        'boston', 'seattle', 'portland', 'philadelphia', 'detroit', 'atlanta', 'texas', 'florida', 'midwest',
        'berlin', 'hamburg', 'paris', 'tokyo', 'osaka', 'melbourne', 'sydney', 'brisbane', 'perth', 'stockholm',
        'gothenburg', 'oslo', 'helsinki', 'amsterdam', 'manchester', 'glasgow', 'leeds', 'bristol', 'aarhus',
        'odense', 'aalborg', 'reykjavik', 'warsaw', 'prague', 'vienna', 'zurich', 'madrid', 'barcelona',
        'milan', 'rome', 'lisbon', 'dublin', 'seoul', 'mexicocity', 'saopaulo', 'buenosaires',
    ];

    /** Exact genres whose keywords would otherwise point to the wrong family. */
    private const FAMILY_OVERRIDES = [
        'hardcore hip hop' => 'Hip hop',
        'hardcore rap' => 'Hip hop',
        'emo rap' => 'Hip hop',
        'hardcore techno' => 'Electronic',
        'industrial metal' => 'Metal',
        'industrial rock' => 'Rock & indie',
        'rap metal' => 'Metal',
        'rap rock' => 'Rock & indie',
        'dance pop' => 'Pop',
        'dance-pop' => 'Pop',
        'dance punk' => 'Punk',
        'electropop' => 'Pop',
        'synthpop' => 'Pop',
    ];

    /**
     * Phrases that belong to another family than a keyword inside them says,
     * checked before FAMILIES (so they also cover longer tags such as "german
     * happy hardcore"). Only where MusicBrainz' genre relations are clear:
     * these are rave / hardcore techno, not hardcore punk. Left as they were
     * on purpose: "uk hardcore" (MusicBrainz: happy hardcore, but also used for
     * British hardcore punk) and "digital hardcore" (a fusion of hardcore punk
     * and hardcore techno) - one family would be a guess.
     */
    private const FAMILY_PHRASES = [
        'happy hardcore' => 'Electronic',     // subgenre of hardcore techno
        'freeform hardcore' => 'Electronic',  // subgenre of hardcore techno
        'uptempo hardcore' => 'Electronic',   // subgenre of hardcore techno
        'breakbeat hardcore' => 'Electronic', // subgenre of breakbeat
        'deathchant hardcore' => 'Electronic', // subgenre of hardcore techno
        'industrial hardcore' => 'Electronic', // subgenre of hardcore techno
        'hardcore breaks' => 'Electronic',    // subgenre of breakbeat hardcore
    ];

    /** Families in priority order: first matching keyword wins. */
    private const FAMILIES = [
        'Post-hardcore & emo' => ['post-hardcore', 'emo', 'screamo', 'skramz'],
        'Hardcore' => ['hardcore', 'beatdown', 'powerviolence', 'youth crew', 'moshcore', 'crossover'],
        'Metalcore' => ['metalcore', 'deathcore', 'mathcore'],
        'Punk' => ['punk', 'skate', 'ska'],
        'Hip hop' => ['hip hop', 'rap', 'trap', 'grime', 'boom bap', 'drill', 'turntablism'],
        'Latin & reggaeton' => ['reggaeton', 'latin', 'perreo', 'cumbia', 'dembow', 'rkt', 'bachata', 'salsa'],
        'Electronic' => ['techno', 'house', 'electronic', 'electronica', 'edm', 'dance', 'trance', 'dubstep',
            'drum and bass', 'jungle', 'idm', 'rave', 'breakbeat', 'gabber', 'hardstyle', 'bass music',
            'uk garage', 'pc music', 'electroclash', 'bubblegum bass', 'hyperpop', 'synthwave', 'ebm', 'industrial', 'ambient'],
        'Metal' => ['metal', 'thrash', 'djent', 'grindcore', 'sludge', 'doom', 'stoner'],
        'Pop' => ['pop'],
        'R&B & soul' => ['r&b', 'soul', 'funk', 'neo-soul'],
        'Rock & indie' => ['rock', 'indie', 'grunge', 'noise', 'shoegaze', 'alternative', 'garage rock'],
        'Folk & singer-songwriter' => ['folk', 'singer-songwriter', 'acoustic', 'country', 'americana'],
        'Jazz & blues' => ['jazz', 'blues'],
        'Classical & soundtrack' => ['classical', 'soundtrack', 'score', 'orchestral'],
    ];

    /** Nationality/country tags => ISO 3166-1 code (used to cross-check MusicBrainz). */
    private const COUNTRIES = [
        'danish' => 'DK', 'dansk' => 'DK', 'denmark' => 'DK', 'danmark' => 'DK', 'copenhagen' => 'DK',
        'american' => 'US', 'usa' => 'US', 'us' => 'US', 'californian' => 'US', 'california' => 'US', 'newyork' => 'US',
        'british' => 'GB', 'uk' => 'GB', 'english' => 'GB', 'london' => 'GB', 'scottish' => 'GB', 'welsh' => 'GB',
        'canadian' => 'CA', 'canada' => 'CA', 'australian' => 'AU', 'australia' => 'AU', 'swedish' => 'SE',
        'sweden' => 'SE', 'irish' => 'IE', 'ireland' => 'IE', 'german' => 'DE', 'germany' => 'DE',
        'norwegian' => 'NO', 'norway' => 'NO', 'french' => 'FR', 'france' => 'FR', 'belgian' => 'BE',
        'belgium' => 'BE', 'dutch' => 'NL', 'finnish' => 'FI', 'newzealand' => 'NZ', 'japanese' => 'JP',
        'japan' => 'JP', 'korean' => 'KR', 'korea' => 'KR', 'chinese' => 'CN', 'china' => 'CN',
        'argentina' => 'AR', 'argentinian' => 'AR', 'brazilian' => 'BR', 'brazil' => 'BR', 'spanish' => 'ES',
        'spain' => 'ES', 'italian' => 'IT', 'italy' => 'IT', 'mexican' => 'MX', 'mexico' => 'MX', 'polish' => 'PL',
        'icelandic' => 'IS',
    ];

    /**
     * Countries implied by an artist's meta tags, e.g. ['danish', 'dansk'] => ['DK'].
     *
     * @param list<string> $metaTags
     * @return list<string>
     */
    public static function countries(array $metaTags): array
    {
        $codes = [];
        foreach ($metaTags as $t) {
            $code = self::COUNTRIES[self::key($t)] ?? null;
            if ($code !== null) {
                $codes[$code] = true;
            }
        }
        return array_keys($codes);
    }

    /**
     * Genre families for a plain list of tag names (e.g. MusicBrainz tags).
     *
     * @param list<string> $tags
     * @return list<string>
     */
    public static function familiesOf(array $tags): array
    {
        $families = [];
        foreach ($tags as $t) {
            if (self::classify($t) === 'genre') {
                $f = self::family($t);
                if ($f !== 'Other') {
                    $families[$f] = true;
                }
            }
        }
        return array_keys($families);
    }

    public static function key(string $tag): string
    {
        // Accents folded ("musique concrète" = "musique concrete"), then letters and digits only.
        $folded = strtr(mb_strtolower(trim($tag)), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'æ' => 'ae',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o',
            'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
        return (string) preg_replace('/[^a-z0-9&]+/u', '', $folded);
    }

    /**
     * Display name for a tag (merges spelling variants): our aliases, then
     * MusicBrainz' own spelling of a genre ("neo-soul" -> "neo soul",
     * "psy-trance" -> "psytrance"), else the tag in lower case.
     */
    public static function canonical(string $tag): string
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $tag)));
        $name = (string) preg_replace(['/\bhip-?hop\b/', '/\brnb\b/'], ['hip hop', 'r&b'], $name);
        $key = self::key($name);
        return self::ALIASES[$key] ?? self::classes()['spelling'][$key] ?? $name;
    }

    /** The musical property dimension of a tag ("aggressive" -> "energy"), or null. */
    public static function property(string $tag): ?string
    {
        return self::classes()['property_of'][self::key($tag)] ?? null;
    }

    /** @return 'genre'|'property'|'meta'|'noise'|'unknown' */
    public static function classify(string $tag, string $artist = ''): string
    {
        $key = self::key($tag);
        if ($key === '' || in_array($key, array_map(self::key(...), self::NOISE), true)) {
            return 'noise';
        }
        if ($artist !== '' && $key === self::key($artist)) {
            return 'noise'; // tagged with its own name
        }
        if (preg_match('/^(19|20)?\d0s$/', $key) || preg_match('/^(19|20)\d\d$/', $key)) {
            return 'meta'; // decades and years
        }
        if (in_array($key, array_map(self::key(...), self::META), true) || in_array($key, self::PLACES, true) || isset(self::classes()['meta_keys'][$key])) {
            return 'meta';
        }
        // Numbers/ratings and fragments ("3-5", "4/5", "2") are not genres.
        if (!preg_match('/[a-z]{3}/', $key) && !isset(self::ALIASES[$key]) && !in_array($key, ['r&b', 'uk', 'lofi'], true)) {
            return 'noise';
        }
        // Web addresses and run-together handles ("bandcamp com", "drjazzmrfunkmusic").
        if (str_contains($key, 'bandcamp') || str_contains($key, 'www') || preg_match('/(com|net|org)$/', $key) && str_contains(mb_strtolower($tag), ' ')
            || (strlen($key) > 16 && !str_contains(trim($tag), ' ') && !str_contains($tag, '-'))) {
            return 'noise';
        }
        $c = self::classes();
        $lower = mb_strtolower(trim($tag));
        foreach ($c['noise_patterns'] as $pattern) {
            if (preg_match($pattern, $lower)) {
                return 'noise';
            }
        }
        foreach ($c['meta_patterns'] as $pattern) {
            if (preg_match($pattern, $lower)) {
                return 'meta';
            }
        }
        if (isset($c['property_of'][$key])) {
            return 'property';
        }
        return self::isGenre(self::canonical($tag)) ? 'genre' : 'unknown';
    }

    /**
     * A documented genre: on MusicBrainz' list or our extra genres (incl.
     * aliases), or built from a genre word - a family keyword as a whole word
     * ("metallic hardcore", "electro techno") or a genre word ending a
     * compound ("synthpunk", "slowcore").
     */
    private static function isGenre(string $canonical): bool
    {
        $c = self::classes();
        if (isset($c['genre_keys'][self::key($canonical)]) || in_array($canonical, self::ALIASES, true)) {
            return true;
        }
        return (bool) preg_match($c['genre_word_pattern'], $canonical);
    }

    /** The classification lists, loaded once (api/data). */
    private static function classes(): array
    {
        static $c = null;
        if ($c !== null) {
            return $c;
        }
        $data = require __DIR__ . '/../../data/tag-classes.php';
        $genres = array_filter(array_map('trim', file(__DIR__ . '/../../data/musicbrainz-genres.txt', FILE_IGNORE_NEW_LINES) ?: []),
            static fn ($l) => $l !== '' && !str_starts_with($l, '#'));
        $c = ['noise_patterns' => $data['noise_patterns'], 'meta_patterns' => $data['meta_patterns'], 'property_of' => [], 'genre_keys' => [], 'spelling' => [],
            'meta_keys' => array_fill_keys(array_map(self::key(...), $data['meta']), true)];
        foreach ([...$genres, ...$data['genres']] as $g) {
            $c['genre_keys'][self::key($g)] = true;
            $c['spelling'][self::key($g)] ??= $g;
        }
        foreach ($data['synonyms'] as $other => $genre) {
            $c['genre_keys'][self::key($other)] = true;
            $c['spelling'][self::key($other)] = $genre;
        }
        foreach ($data['properties'] as $dimension => $words) {
            foreach ($words as $w) {
                $c['property_of'][self::key($w)] = $dimension;
            }
        }
        $quote = static fn (array $list) => implode('|', array_map(static fn ($w) => preg_quote($w, '/'), $list));
        $c['genre_word_pattern'] = '/(^|[\s\/-])(' . $quote(array_merge(...array_values(self::FAMILIES))) . ')($|[\s\/-])|('
            . $quote($data['genre_suffixes']) . ')$/u';
        return $c;
    }

    public static function family(string $genre): string
    {
        $g = self::canonical($genre);
        if (isset(self::FAMILY_OVERRIDES[$g])) {
            return self::FAMILY_OVERRIDES[$g];
        }
        foreach (self::FAMILY_PHRASES as $phrase => $family) {
            if (str_contains($g, $phrase)) {
                return $family;
            }
        }
        foreach (self::FAMILIES as $family => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($g, $kw)) {
                    return $family;
                }
            }
        }
        return 'Other';
    }

    /**
     * Splits an artist's raw tags into kept genres (with weights normalised to
     * sum 1) plus everything that was set aside, so nothing disappears silently.
     *
     * @param list<array{name: string, count: int}> $tags
     * @return array{
     *   genres: array<string, float>,
     *   meta: list<string>, property: list<string>, noise: list<string>, unknown: list<string>, low_support: list<string>
     * }
     */
    public static function split(array $tags, string $artist, int $maxGenres = 6): array
    {
        $out = ['genres' => [], 'meta' => [], 'property' => [], 'noise' => [], 'unknown' => [], 'low_support' => []];
        $raw = [];

        foreach ($tags as $t) {
            $name = self::canonical($t['name']);
            $kind = self::classify($t['name'], $artist);
            if ($kind !== 'genre') {
                $out[$kind][] = $name;
                continue;
            }
            if ($t['count'] < self::MIN_COUNT) {
                $out['low_support'][] = $name;
                continue;
            }
            // Variants of the same genre: keep the strongest weight.
            $raw[$name] = max($raw[$name] ?? 0, $t['count']);
        }

        arsort($raw);
        $raw = array_slice($raw, 0, $maxGenres, true);
        $sum = array_sum($raw);
        foreach ($raw as $name => $count) {
            $out['genres'][$name] = $sum > 0 ? $count / $sum : 0.0;
        }
        foreach (['meta', 'property', 'noise', 'unknown', 'low_support'] as $k) {
            $out[$k] = array_values(array_unique($out[$k]));
        }

        return $out;
    }
}
