<?php

declare(strict_types=1);

namespace MusicRadar\Recommend;

use MusicRadar\Taste\TagClassifier;

/**
 * Rule-based recommendation engine - runs in plain PHP, no paid services.
 *
 * For each release it builds a tag profile from documented facts only
 * (MusicBrainz and Last.fm tags, with their fact ids), compares it with the
 * TasteModel and combines separate kinds of evidence - each counted once:
 *
 *   genre    ONE genre signal: the information in the release's genre tags
 *            that you listen to (TagInformation: broad tags tell less; each
 *            tag once; your ratings adjust how much you like a tag), shrunk by
 *            "full information" so thin data cannot score high. Tag overlap
 *            with one of your artists is an alternative route to it (the larger
 *            counts), never an extra bonus. Families give no points of their own.
 *   lastfm   Last.fm Similar Artists links to your artists (SimilarArtists) -
 *            independent of the tags; capped, so it never decides alone
 *   music    1 - (1 - genre)(1 - lastfm_cap x lastfm)
 *   + Danish artist, already-known artist, outside (penalty), your ratings of
 *     the same artist (RatingSignal); data confidence apart from the score
 *   novelty, blend: what is new to you - for wildcards, not for points
 *
 * "Recommended for You" = high fit. "Wildcards" = a documented bridge to your
 * taste AND something new - a deliberate challenge, never random. Releases
 * from artists you follow or are interested in (any credited artist) are
 * always recommended. Reasons only cite facts. All weights/thresholds come
 * from config/recommender.php.
 *
 * Artist interest (ArtistInterest, set on the release as 'interest') is kept
 * apart from the music score: fit - the score shown - never includes it. It
 * only enters the decision: a release by an artist you have listened to may
 * be recommended when fit + assist_weight * interest reaches the threshold,
 * provided the music itself fits at least assist_min_fit.
 */
final class RecommendationEngine
{
    private const TAG_FACTS = [
        'Release tags' => 'musicbrainz:release-group',
        'Artist genres/tags' => 'musicbrainz:artist',
        'Artist tags on Last.fm (strongest first)' => 'lastfm:artist',
        'Album tags on Last.fm' => 'lastfm:album',
    ];

    private RatingSignal $ratings;
    /** @var array<string, true> release ids */
    private array $notInterested = [];
    /** @var array<string, int> your own star rating per release id */
    private array $ownStars = [];

    /**
     * Feedback in the shape of Feedback\FeedbackSet::forEngine(). "Save for
     * later" is deliberately not part of it: a bookmark is not a taste signal.
     *
     * @param array{ratings?: list<array{tags: list<string>, stars: int, release_id?: string, artist_mbid?: ?string, artist?: string, title?: string}>, not_interested_releases?: list<string>} $feedback
     */
    public function __construct(
        private readonly TasteModel $taste,
        private readonly array $config,
        array $feedback = [],
    ) {
        $enabled = $config['ratings']['enabled'] ?? true;
        $this->ratings = new RatingSignal($taste, $enabled ? ($feedback['ratings'] ?? []) : [], $config['ratings'] ?? []);
        $this->notInterested = array_fill_keys($feedback['not_interested_releases'] ?? [], true);
        foreach ($feedback['ratings'] ?? [] as $r) {
            if (($r['release_id'] ?? null) !== null && !($r['not_interested'] ?? false)) {
                $this->ownStars[$r['release_id']] = (int) $r['stars'];
            }
        }
    }

    /**
     * You rated this very release 1-2 stars: it is not recommended to you (or
     * offered as a wildcard), including releases from known artists.
     */
    private function ratedLow(string $id): bool
    {
        return isset($this->ownStars[$id]) && $this->ownStars[$id] <= 2;
    }

    /**
     * Scores all releases and selects recommendations and wildcards.
     *
     * @param array<string, array<string, mixed>> $releases enriched releases by id
     */
    public function run(array $releases): array
    {
        // How much each genre tag tells, learned from the releases being judged.
        $vectors = [];
        foreach ($releases as $r) {
            if (($v = array_map(static fn ($t) => $t['weight'], $this->evidence($r)['tags'])) !== []) {
                $vectors[] = $v;
            }
        }
        $this->ratings->learnRelations($vectors);
        if (count($vectors) >= (int) ($this->config['tag_information']['min_releases'] ?? 1)) {
            $this->taste->useTagInformation(TagInformation::fromVectors($vectors, $this->config));
        }
        $assessments = [];
        foreach ($releases as $id => $r) {
            $assessments[$id] = $this->assess($r);
        }

        $recCfg = $this->config['recommended'];
        $wildCfg = $this->config['wildcards'];
        $always = $recCfg['always_include'];

        // Only releases with a confirmed release DAY can be presented as new.
        $eligible = array_filter($assessments, static fn ($a) => !$a['excluded'] && $a['date_status'] === 'confirmed');

        // 1) Wildcards first: deliberate bets (strong novelty + a specific
        //    bridge), not just strong matches. No cap and no quota per family:
        //    every release that is a real bet is one (best bets first).
        $wild = [];
        foreach ($eligible as $id => $a) {
            $level = $releases[$id]['watch_level'] ?? null;
            if ($a['assessable'] && !in_array($level, $always, true) && !$this->ratedLow($id) && $this->isWildcard($a)) {
                $wild[$id] = $a;
            }
        }
        uasort($wild, static fn ($a, $b) => $b['wild_score'] <=> $a['wild_score']);
        $wildcards = [];
        foreach ($wild as $id => $a) {
            $wildcards[$id] = $this->pick($id, $a, 'wildcard', 'discovery', $releases[$id]);
        }

        // 2) Recommended for You - no fixed limit:
        //    - known artists (always_include levels) whatever the score
        //    - every other release with a good enough fit and enough data
        //      confidence (the fit itself needs several pieces of evidence to
        //      be high: genre information, Last.fm links, your ratings)
        //    - never a release you rated 1-2 stars yourself
        $rec = [];
        foreach ($eligible as $id => $a) {
            if (isset($wildcards[$id])) {
                continue;
            }
            if ($this->ratedLow($id)) {
                $assessments[$id]['not_selected'] = 'you rated this release ' . $this->ownStars[$id] . ' star(s)';
                continue;
            }
            $level = $releases[$id]['watch_level'] ?? null;
            if (in_array($level, $always, true)) {
                $rec[$id] = $this->pick($id, $a, 'recommended', $level, $releases[$id]);
                continue;
            }
            // The decision may include artist interest; the score (fit) does not.
            $decide = $a['fit'] + $a['interest_boost'];
            if (!$a['assessable'] || $decide < $recCfg['min_fit']) {
                continue;
            }
            if ($a['confidence'] >= $recCfg['min_confidence']) {
                $rec[$id] = $this->pick($id, $a, 'recommended', 'discovery', $releases[$id]);
                $rec[$id]['interest_assisted'] = $a['fit'] < $recCfg['min_fit'];
            } else {
                $assessments[$id]['not_selected'] = 'too little data for this fit';
            }
        }
        // 3) Artists you have listened to: a release that is neither a match nor a
        //    bet can still be worth checking out because you know the artist - a
        //    wildcard of its own kind, never a recommendation (see listenedBet()).
        foreach ($eligible as $id => $a) {
            if (!isset($rec[$id]) && !isset($wildcards[$id]) && $this->listenedBet($id, $a, $releases[$id])) {
                $wildcards[$id] = $this->pick($id, $a, 'wildcard', 'discovery', $releases[$id], 'known-artist');
            }
        }
        // 4) Too little music data to recommend, but a concrete reason to be
        //    curious (Last.fm links to your artists, or your good ratings of the
        //    artist) - thin data alone is never a reason (see limitedDataBet()).
        foreach ($eligible as $id => $a) {
            $level = $releases[$id]['watch_level'] ?? null;
            if (!isset($rec[$id]) && !isset($wildcards[$id]) && !in_array($level, $always, true) && $this->limitedDataBet($id, $a)) {
                $wildcards[$id] = $this->pick($id, $a, 'wildcard', 'discovery', $releases[$id], 'limited-data');
            }
        }
        $recommended = $this->diversify($rec, $releases);

        foreach ($assessments as $id => &$a) {
            $a['decision'] = match (true) {
                isset($rec[$id]) => 'recommended',
                isset($wildcards[$id]) => 'wildcard',
                $a['date_status'] !== 'confirmed' => 'needs_date_check',
                !$a['assessable'] => 'not_assessable',
                $a['excluded'] => 'excluded',
                default => 'not_selected',
            };
        }
        unset($a);

        return ['recommended' => $recommended, 'wildcards' => array_values($wildcards), 'assessments' => $assessments];
    }

    /**
     * A real musical bet: a family that is (nearly) absent from your profile,
     * or a blend of liked families that are NOT closely related (hardcore +
     * electronic yes, hardcore + metalcore no) - plus a SPECIFIC bridge back to
     * your taste (a concrete neighbour artist or a specific shared genre).
     */
    private function isWildcard(array &$a): bool
    {
        $c = $this->config['wildcards'];
        $s = $a['signals'];
        if ($a['confidence'] < $c['min_confidence'] || $a['fit'] < $c['min_fit'] || $a['fit'] >= $c['max_fit'] || $s['outside'] > $c['max_outside']) {
            return false;
        }
        $newTerritory = $a['new_family_share'] >= $c['min_new_family_share'];
        $boldBlend = $a['unrelated_blend'] !== null;
        $bridge = $s['neighbours'] >= $c['min_neighbour_bridge'] || $a['specific_shared'] !== [];
        if (!($newTerritory || $boldBlend) || !$bridge) {
            return false;
        }
        $a['wild_score'] = 0.4 * max($s['neighbours'], $s['genre']) + 0.4 * $a['new_family_share'] + 0.2 * ($boldBlend ? 1 : 0);
        return true;
    }

    /**
     * Orders recommendations by fit (thin data counts a little less), with a
     * small penalty for each earlier pick from the same "cluster" (same lead
     * neighbour artist) or the same artist - so near-identical releases don't
     * crowd the top. No quotas: everything relevant stays in the list.
     * Known artists come first (following, then interested), so they are
     * never lost further down a long list.
     *
     * @param array<string, array<string, mixed>> $picks
     * @return list<array<string, mixed>>
     */
    private function diversify(array $picks, array $releases): array
    {
        $cfg = $this->config['recommended'];
        $out = [];
        $rest = $picks;
        foreach (['following', 'interested'] as $origin) {
            $known = array_filter($rest, static fn ($p) => $p['origin'] === $origin);
            uasort($known, static fn ($a, $b) => $b['fit'] <=> $a['fit']);
            array_push($out, ...array_values($known));
            $rest = array_diff_key($rest, $known);
        }
        $clusterCount = [];
        $artistCount = [];
        while ($rest !== []) {
            $bestId = null;
            $best = -INF;
            foreach ($rest as $id => $p) {
                $score = $p['fit'] * (0.75 + 0.25 * min(1.0, $p['confidence']))
                    - $cfg['diversity_penalty'] * ($clusterCount[$p['cluster']] ?? 0)
                    - $cfg['same_artist_penalty'] * ($artistCount[$releases[$id]['artist']['mbid'] ?? ''] ?? 0);
                if ($score > $best) {
                    $best = $score;
                    $bestId = $id;
                }
            }
            $p = $rest[$bestId];
            unset($rest[$bestId]);
            $clusterCount[$p['cluster']] = ($clusterCount[$p['cluster']] ?? 0) + 1;
            $mbid = $releases[$bestId]['artist']['mbid'] ?? '';
            $artistCount[$mbid] = ($artistCount[$mbid] ?? 0) + 1;
            $out[] = $p;
        }
        return $out;
    }

    /** All signals + reasons for one release. */
    public function assess(array $r): array
    {
        $a = ['date_status' => self::dateStatus($r)] + $this->assessMusic($r);
        $a['interest_level'] = $r['interest']['level'] ?? (isset($r['watch_level']) ? $r['watch_level'] : 'unknown');
        $a['interest'] = (float) ($r['interest']['value'] ?? 0.0);
        $a['interest_boost'] = $this->interestBoost($a);
        // "Not interested" hides this one release - it says nothing about the
        // music, so it changes no score, only the decision.
        if (isset($this->notInterested[$r['id'] ?? ''])) {
            $a['excluded'] = true;
            $a['note'] = 'you marked this release "Not interested"';
        }
        return $a;
    }

    /**
     * How far artist interest lifts the decision (not the score): only for
     * "listened" artists (following/interested are always included anyway),
     * only when the music itself is a near miss, never past what the plays say.
     */
    private function interestBoost(array $a): float
    {
        $c = $this->config['interest'] ?? [];
        if (!($c['enabled'] ?? false) || $a['interest_level'] !== 'listened' || !$a['assessable'] || $a['fit'] < (float) $c['assist_min_fit']) {
            return 0.0;
        }
        return round((float) $c['assist_weight'] * $a['interest'], 4);
    }

    /** confirmed | probable | unconfirmed (see Discovery\DateVerifier). */
    public static function dateStatus(array $r): string
    {
        return $r['date_check']['status'] ?? (strlen((string) ($r['first_release_date'] ?? '')) === 10 ? 'confirmed' : 'unconfirmed');
    }

    private function assessMusic(array $r): array
    {
        $ev = $this->evidence($r);
        $vector = array_map(static fn ($t) => $t['weight'], $ev['tags']);
        $cfg = $this->config;
        $w = $cfg['weights'];
        $model = $cfg['model'];

        $base = ['assessable' => $vector !== [], 'excluded' => false, 'fit' => 0.0, 'confidence' => 0.0,
            'signals' => [], 'reasons' => [], 'primary_family' => null, 'new_blend' => false, 'novel_family' => null,
            'new_family_share' => 0.0, 'unrelated_blend' => null, 'specific_shared' => [], 'tag_artists' => [], 'lastfm_artists' => []];
        if ($vector === []) {
            return $this->assessWithoutGenres($r, $ev, $base);
        }

        // Families of the release (by tag weight) - for bets, "outside" and
        // wording. They give no points of their own (see the genre signal).
        $fam = [];
        foreach ($vector as $t => $wt) {
            $f = TagClassifier::family($t);
            if ($f !== 'Other') {
                $fam[$f] = ($fam[$f] ?? 0) + $wt;
            }
        }
        arsort($fam);
        $famTotal = array_sum($fam) ?: 1;
        $famShare = array_map(static fn ($x) => $x / $famTotal, $fam);

        // 1. Genre: ONE signal. Each tag counts once, by how much it tells about
        //    the music (spelling variants are one tag; a tag contained in a more
        //    specific one adds nothing). How much you like it: your profile; an
        //    undocumented sub-genre of a genre or family you like counts half
        //    ("industrial techno" when you listen to "techno"); your ratings of
        //    releases with the tag move it. Shrunk toward 0 by "full
        //    information", so thin data cannot reach a high score.
        $info = $this->genreInformation(array_keys($vector));
        $full = $this->taste->fullInformation();
        $likedInfo = 0.0;
        $genreContrib = [];
        $ratingLean = [];
        $pref = [];
        $prefOf = function (string $t) use (&$pref, $r): float {
            return $pref[$t] ??= $this->ratings->tagPreference($t, $r['id'] ?? null, $r['artist']['mbid'] ?? null);
        };
        $listed = [...$info['specific'], ...$info['broad']];
        $parentLiked = [];
        foreach (array_diff(array_keys($vector), $listed) as $parent) {
            $likedParent = min(1.0, ($this->taste->genres[$parent] ?? 0) / (float) $cfg['liked_genre_ref']);
            foreach ($likedParent > 0 ? $listed : [] as $child) {
                if (self::contains($child, $parent)) {
                    $parentLiked[$child] = max($parentLiked[$child] ?? 0.0, $likedParent);
                }
            }
        }
        $relatedLean = [];
        $specific = array_flip($info['specific']);
        foreach ($listed as $t) {
            $direct = min(1.0, ($this->taste->genres[$t] ?? 0) / (float) $cfg['liked_genre_ref']);
            $liked = $direct;
            if ($this->taste->isLikedFamily(TagClassifier::family($t))) {
                $liked = max($liked, (float) $model['family_fallback']);
            }
            $liked = max($liked, (float) $model['family_fallback'] * ($parentLiked[$t] ?? 0.0));
            $lean = $prefOf($t);
            $related = false;
            // A specific sub-genre that is neither in your profile nor rated itself:
            // half of what your ratings of related sub-genres say, as it gets half
            // of a liked family. Never for broad tags, never on top of the tag's
            // own ratings.
            if ($direct < (float) $model['family_fallback'] && isset($specific[$t]) && !$this->ratings->ratedTag($t, $r['id'] ?? null, $r['artist']['mbid'] ?? null)) {
                $lean = (float) $model['family_fallback'] * $this->ratings->relatedPreference($t, $r['id'] ?? null, $r['artist']['mbid'] ?? null);
                $related = true;
            }
            if ($lean !== 0.0) {
                $adjusted = max(0.0, min(1.0, $liked + $lean));
                if (abs($adjusted - $liked) >= 0.01) {
                    if ($related) {
                        $relatedLean[$t] = round($adjusted - $liked, 3);
                    } else {
                        $ratingLean[$t] = round($adjusted - $liked, 3);
                    }
                }
                $liked = $adjusted;
            }
            $u = $this->taste->specificity($t);
            $likedInfo += $u * $liked;
            if ($liked > 0) {
                $genreContrib[$t] = $u * $liked;
            }
        }
        arsort($genreContrib);
        $genreMatch = $likedInfo / ($info['amount'] + $full);

        // 2. Tag overlap with one of your artists - the same tags, so only an
        //    alternative route to the genre signal (the larger counts), never a
        //    bonus. It counts by the information actually shared: one shared tag
        //    is limited evidence however alike the two tag lists look. Your
        //    ratings of the shared tags move it as they move the genre match.
        // 3. Last.fm Similar Artists - independent of the tags (see SimilarArtists).
        $tagArtists = [];
        [$lastfm, $lastfmArtists] = $this->lastfmSignal($r);
        foreach ($this->taste->artists as $art) {
            [$sim, $contrib] = $this->taste->similarity($vector, $art['vector']);
            if ($contrib !== []) {
                $specific = array_sum(array_filter($contrib, fn ($c, $t) => $this->taste->isSpecific($t), ARRAY_FILTER_USE_BOTH));
                $sim *= max((float) $cfg['generic_weight'], $specific / array_sum($contrib));
                $shared = $this->genreInformation(array_keys($contrib))['amount'];
                $lean = array_sum(array_map(static fn ($t, $c) => $prefOf($t) * $c, array_keys($contrib), $contrib)) / array_sum($contrib);
                $overlap = min(1.0, $sim * $shared / ($shared + $full) * max(0.0, 1 + $lean));
                if ($overlap > 0) {
                    $tagArtists[$art['name']] = ['weighted' => $overlap * $art['weight'], 'overlap' => round($overlap, 3), 'shared' => array_slice(array_keys($contrib), 0, 3)];
                }
            }
        }
        uasort($tagArtists, static fn ($a, $b) => $b['weighted'] <=> $a['weighted']);
        $overlap = $tagArtists ? reset($tagArtists)['weighted'] : 0.0;
        $genre = max($genreMatch, $overlap);
        // Independent evidence: each source has a ceiling (Last.fm alone at most
        // lastfm_cap), a missing source adds nothing, sources that agree add up.
        $music = 1 - (1 - $genre) * (1 - (float) $model['lastfm_cap'] * $lastfm);
        $bestOverlap = $tagArtists ? max(array_column($tagArtists, 'overlap')) : 0.0;
        $bestLink = $lastfmArtists ? max(array_column($lastfmArtists, 'link')) : 0.0;
        $neighbours = max($bestOverlap, $bestLink); // the bridge back to your taste, for bets

        // blend: several liked families; is that mix new among your artists?
        $liked = array_values(array_filter(array_keys($famShare), fn ($f) => $this->taste->isLikedFamily($f) && $famShare[$f] >= 0.15));
        $blend = 0.0;
        $newBlend = false;
        if (count($liked) >= 2) {
            $blend = min($this->taste->familyWeight($liked[0]), $this->taste->familyWeight($liked[1])) / $this->taste->maxFamilyWeight();
            $pair = [$liked[0], $liked[1]];
            sort($pair);
            $newBlend = !isset($this->taste->familyPairs[implode('|', $pair)]);
        }

        // novelty and outside
        $novelFam = 0.0;
        $outside = 0.0;
        $novelFamily = null;
        foreach ($famShare as $f => $sh) {
            if (!$this->taste->isLikedFamily($f)) {
                $novelFam += $sh;
                $novelFamily ??= $f;
                if (!$this->taste->isNearTaste($f)) {
                    $outside += $sh;
                }
            }
        }
        $vecTotal = array_sum($vector);
        $novelTags = array_keys(array_diff_key($vector, $this->taste->genres));
        $tagNovelty = $vecTotal > 0 ? array_sum(array_intersect_key($vector, array_flip($novelTags))) / $vecTotal : 0;
        // New families count most; new sub-tags within a liked family less.
        $novelty = 0.7 * $novelFam + 0.3 * $tagNovelty;
        if ($famShare === []) {
            $outside = 0.0; // only unclassified tags: unknown, not "outside"
        }

        // Wildcard material: share of (nearly) unknown families, a mix of
        // families that are not relatives and that none of your artists has,
        // and specific genres you like (the bridge back to your taste).
        $wc = $cfg['wildcards'];
        $newFamilyShare = 0.0;
        foreach ($famShare as $f => $sh) {
            if ($this->taste->familyWeight($f) < $wc['novel_family_max']) {
                $newFamilyShare += $sh;
            }
        }
        $major = array_slice(array_keys(array_filter($famShare, static fn ($sh) => $sh >= 0.2)), 0, 3);
        $unrelatedBlend = null;
        foreach ($major as $i => $f1) {
            foreach (array_slice($major, $i + 1) as $f2) {
                $pair = [$f1, $f2];
                sort($pair);
                if ($unrelatedBlend === null && !$this->taste->areRelated($f1, $f2)
                    && !isset($this->taste->familyPairs[implode('|', $pair)])
                    && ($this->taste->isLikedFamily($f1) || $this->taste->isLikedFamily($f2))) {
                    $unrelatedBlend = [$f1, $f2];
                }
            }
        }
        $specificShared = array_keys(array_filter($vector, fn ($wt, $t) => $this->taste->isSpecific($t)
            && ($this->taste->genres[$t] ?? 0) >= $cfg['liked_genre_ref'], ARRAY_FILTER_USE_BOTH));

        $danish = $ev['danish'] ? 1.0 : 0.0;
        $known = isset($r['watch_level']) ? 1.0 : 0.0;

        // Your ratings of other releases by the same artist (named in the reasons).
        $rated = $this->ratings->assess($vector, $r['artist']['mbid'] ?? null, $r['id'] ?? null);

        // Dynamic blend: score = (1 - w) x music + w x personal. Music (genre +
        // Last.fm, minus "outside") is the general assumption; personal is your
        // own opinion of this artist. w grows smoothly with what we know:
        // E = ratings + plays (up to plays_weight), w = E / (E + prior).
        // Personal = the mean of your ratings; plays count as plays_opinion (you
        // like it, not enthusiasm) with a weight that shrinks as ratings come
        // in, so ratings take precedence. Both parts are 0..1, so the score is too.
        $musicPart = max(0.0, $music - $w['outside'] * $outside);
        [$fit, $personalWeight, $rating] = $this->blend($r, $musicPart);
        // The same terms one by one (for explaining a score; they add up to $fit).
        $scoreParts = array_map(static fn ($x) => round($x, 4), [
            'genre' => (1 - $personalWeight) * $genre,
            'lastfm' => (1 - $personalWeight) * ($music - $genre),
            'danish' => 0.0,
            'known' => 0.0,
            'outside' => (1 - $personalWeight) * ($musicPart - $music),
            'rating' => $rating,
        ]);

        // Data confidence: how well the documentation supports this assessment -
        // the amount of genre information (two typical specific tags = full,
        // independent sources counted once) times the sources' quality, OR
        // documented Last.fm links to your artists. Apart from the score.
        $quality = ['good' => 1.0, 'partial' => 0.6][$r['metadata_quality'] ?? ''] ?? 0.0;
        $tagConfidence = $quality * min(1.0, $info['amount'] / $full);
        $confidence = 1 - (1 - $tagConfidence) * (1 - (float) $model['lastfm_confidence'] * $lastfm);

        $compilation = (bool) preg_match((string) $cfg['exclude_title_pattern'], (string) ($r['title'] ?? ''));

        $a = [
            'assessable' => true,
            'excluded' => $compilation,
            'fit' => round($fit, 4),
            'confidence' => round($confidence, 2),
            'genre_information' => $info,
            'signals' => array_map(static fn ($x) => round($x, 3), compact('genre', 'lastfm', 'neighbours', 'blend', 'novelty', 'outside', 'danish', 'known', 'rating')
                + ['genre_match' => $genreMatch, 'tag_overlap' => $overlap, 'tag_confidence' => $tagConfidence]),
            'primary_family' => array_key_first($famShare),
            'families' => array_map(static fn ($x) => round($x, 2), $famShare),
            'tag_artists' => array_slice($tagArtists, 0, 3, true),
            'lastfm_artists' => array_slice($lastfmArtists, 0, 3, true),
            'lastfm_identity' => $r['similar']['identity'] ?? null,
            'lastfm_certainty' => $r['similar']['certainty'] ?? null,
            'artist_match' => $tagArtists ? ['name' => array_key_first($tagArtists), 'similarity' => reset($tagArtists)['overlap']] : ['name' => null, 'similarity' => 0.0],
            'rating_lean' => $ratingLean,
            'related_rating_lean' => $relatedLean,
            'new_blend' => $newBlend,
            'novel_family' => $novelFam >= 0.25 ? $novelFamily : null,
            'new_family_share' => round($newFamilyShare, 2),
            'unrelated_blend' => $unrelatedBlend,
            'specific_shared' => $specificShared,
            'tags' => array_map(static fn ($x) => round($x, 2), $vector),
            'rating_parts' => array_diff_key($rated, ['evidence' => 1]),
            'score_parts' => $scoreParts,
        ];
        $a['reasons'] = [...$this->reasons($r, $a, $ev, $genreContrib, $novelTags, $music - $genre), ...$rated['evidence']];
        $a['new_artist_since'] = $ev['begin'];
        return $a + ($compilation ? ['note' => 'title suggests a compilation/reissue/live record'] : []);
    }

    /**
     * Last.fm Similar Artists: links to your profile artists. A link to an
     * artist you play a lot, or rated well, says more: graded by plays
     * (lastfm_play_floor .. 1) and up to 1 + lastfm_rating_boost from good ratings.
     *
     * @return array{0: float, 1: array<string, array{weighted: float, link: float}>}  signal 0..1, linked artists strongest first
     */
    private function lastfmSignal(array $r): array
    {
        $model = $this->config['model'];
        $links = $r['similar']['links'] ?? [];
        $artists = [];
        foreach ($this->taste->artists as $art) {
            if (isset($links[$art['name']])) {
                $strength = ((float) $model['lastfm_play_floor'] + (1 - (float) $model['lastfm_play_floor']) * $art['play_weight'])
                    * (1 + (float) $model['lastfm_rating_boost'] * max(0.0, $this->ratings->artistLean($art['mbid'])));
                $artists[$art['name']] = ['weighted' => $links[$art['name']]['s'] * $art['weight'] * $strength, 'link' => $links[$art['name']]['s']];
            }
        }
        uasort($artists, static fn ($a, $b) => $b['weighted'] <=> $a['weighted']);
        $weighted = array_values(array_column($artists, 'weighted'));
        return [$weighted ? min(1.0, $weighted[0] + 0.15 * array_sum(array_slice($weighted, 1, 2))) : 0.0, $artists];
    }

    /**
     * Dynamic blend: score = (1 - w) x music + w x personal. Music is the
     * general assumption; personal is your own opinion of this artist. w grows
     * smoothly with what we know: E = ratings + plays (up to plays_weight),
     * w = E / (E + prior). Personal = the mean of your ratings; plays count as
     * plays_opinion (you like it, not enthusiasm) with a weight that shrinks as
     * ratings come in, so ratings take precedence. Both parts are 0..1, so the
     * score is too.
     *
     * @return array{0: float, 1: float, 2: float}  score, w, w x personal
     */
    private function blend(array $r, float $music): array
    {
        $pc = $this->config['personal'];
        $opinion = $this->ratings->artistOpinion($r['artist']['mbid'] ?? null, $r['id'] ?? null);
        $plays = ($this->config['interest']['enabled'] ?? true) ? (float) $pc['plays_weight'] * (float) ($r['interest']['value'] ?? 0.0) : 0.0;
        $playsInOpinion = $plays / (1 + $opinion['count']);
        $evidence = $opinion['count'] + $plays;
        $weight = $evidence / ($evidence + (float) $pc['prior']);
        $personal = $opinion['count'] + $playsInOpinion > 0
            ? ($opinion['count'] * $opinion['mean'] + $playsInOpinion * (float) $pc['plays_opinion']) / ($opinion['count'] + $playsInOpinion)
            : 0.0;
        return [(1 - $weight) * $music + $weight * $personal, $weight, $weight * $personal];
    }

    /**
     * A release without any genre information: judged on what we still know -
     * Last.fm links to your artists (music, at most lastfm_cap) and your own
     * ratings and plays of the artist (the dynamic blend). Data confidence comes
     * from the same two sources. Nothing known at all: not judged (no score).
     */
    private function assessWithoutGenres(array $r, array $ev, array $base): array
    {
        $model = $this->config['model'];
        [$lastfm, $lastfmArtists] = $this->lastfmSignal($r);
        $music = (float) $model['lastfm_cap'] * $lastfm;
        [$fit, $personalWeight, $rating] = $this->blend($r, $music);
        if ($lastfm <= 0.0 && $personalWeight <= 0.0) {
            return $base + ['note' => 'no genre information - cannot be judged musically'];
        }
        $confidence = 1 - (1 - (float) $model['lastfm_confidence'] * $lastfm) * (1 - $personalWeight);
        $rated = $this->ratings->assess([], $r['artist']['mbid'] ?? null, $r['id'] ?? null);
        $a = [
            'assessable' => true,
            'no_genre' => true,
            'fit' => round($fit, 4),
            'confidence' => round($confidence, 2),
            'signals' => ['genre' => 0.0, 'lastfm' => round($lastfm, 3), 'neighbours' => round($lastfmArtists ? max(array_column($lastfmArtists, 'link')) : 0.0, 3),
                'blend' => 0.0, 'novelty' => 0.0, 'outside' => 0.0, 'danish' => 0.0, 'known' => 0.0, 'rating' => round($rating, 3),
                'genre_match' => 0.0, 'tag_overlap' => 0.0, 'tag_confidence' => 0.0],
            'families' => [],
            'genre_information' => ['specific' => [], 'broad' => [], 'amount' => 0.0],
            'lastfm_artists' => array_slice($lastfmArtists, 0, 3, true),
            'lastfm_identity' => $r['similar']['identity'] ?? null,
            'lastfm_certainty' => $r['similar']['certainty'] ?? null,
            'artist_match' => ['name' => null, 'similarity' => 0.0],
            'rating_lean' => [],
            'related_rating_lean' => [],
            'tags' => [],
            'rating_parts' => array_diff_key($rated, ['evidence' => 1]),
            'score_parts' => array_map(static fn ($x) => round($x, 4), ['genre' => 0.0, 'lastfm' => (1 - $personalWeight) * $music,
                'danish' => 0.0, 'known' => 0.0, 'outside' => 0.0, 'rating' => $rating]),
        ] + $base;
        $a['reasons'] = [...$this->reasons($r, $a, $ev, [], [], (1 - $personalWeight) * $music), ...$rated['evidence']];
        $a['new_artist_since'] = $ev['begin'];
        return $a;
    }

    /** @return list<array{kind: string, text: string, facts: list<string>}> */
    private function reasons(array $r, array $a, array $ev, array $genreContrib, array $novelTags, float $lastfmPart): array
    {
        $factsFor = static function (array $tags) use ($ev): array {
            $ids = [];
            foreach ($tags as $t) {
                $ids = [...$ids, ...($ev['tags'][$t]['facts'] ?? [])];
            }
            return array_values(array_unique($ids));
        };
        $out = [];

        if (isset($r['watch_level'])) {
            $out[] = ['kind' => 'known', 'text' => $r['watch_level'] === 'following' ? 'You follow this artist' : 'An artist from your own listening history', 'facts' => []];
        }
        // Your plays of a credited artist: first when the match is certain; an
        // uncertain (name-only) match is mentioned after the musical reasons.
        $listened = !isset($r['watch_level']) && ($r['interest']['level'] ?? null) === 'listened' ? ArtistInterest::reason($r['interest']) : null;
        if ($listened !== null && $listened['certain']) {
            $out[] = ['kind' => 'listened', 'text' => $listened['text'], 'facts' => []];
        }
        $top = array_slice(array_keys(array_filter($genreContrib, fn ($c, $t) => $this->taste->isSpecific($t), ARRAY_FILTER_USE_BOTH)), 0, 3)
            ?: array_slice(array_keys($genreContrib), 0, 2);
        if ($top) {
            $out[] = ['kind' => 'genres', 'text' => 'Matches genres you listen to: ' . implode(', ', $top), 'facts' => $factsFor($top)];
        }
        // Shared genre tags with one of your artists: only when that route carries
        // the genre signal - and worded as what it is (shared tags, not sound).
        // Not for the artist itself or a co-credited artist.
        $self = array_map('mb_strtolower', [(string) ($r['artist']['name'] ?? ''), ...array_column($r['artist']['credits'] ?? [], 'name'), ...array_column($r['artist']['credits'] ?? [], 'artist_name')]);
        $tagArtist = array_key_first($a['tag_artists']);
        if ($tagArtist !== null && $a['signals']['tag_overlap'] > $a['signals']['genre_match'] && !in_array(mb_strtolower($tagArtist), $self, true)) {
            $shared = $a['tag_artists'][$tagArtist]['shared'];
            $out[] = ['kind' => 'tags', 'text' => "Shares genre tags with $tagArtist: " . implode(', ', $shared), 'facts' => $factsFor($shared)];
        }
        $lastfmNames = array_values(array_filter(array_keys($a['lastfm_artists']), static fn ($n) => !in_array(mb_strtolower($n), $self, true)));
        if ($lastfmPart >= 0.01 && $lastfmNames) {
            $byName = in_array($a['lastfm_identity'], ['name', 'corrected', 'none'], true) ? ' (artist matched by name on Last.fm)' : '';
            $out[] = ['kind' => 'lastfm', 'text' => 'Last.fm Similar Artists connects it to ' . implode(' and ', array_slice($lastfmNames, 0, 2)) . ', in your listening profile' . $byName, 'facts' => []];
        }
        $up = array_keys(array_filter($a['rating_lean'], static fn ($x) => $x >= 0.05));
        $down = array_keys(array_filter($a['rating_lean'], static fn ($x) => $x <= -0.05));
        if ($up) {
            $out[] = ['kind' => 'rating', 'text' => 'Your ratings favour ' . implode(', ', array_slice($up, 0, 2)), 'facts' => []];
        }
        if ($down) {
            $out[] = ['kind' => 'rating', 'text' => 'Your ratings are lower for ' . implode(', ', array_slice($down, 0, 2)) . ' - counts against it', 'facts' => []];
        }
        $relatedUp = array_keys(array_filter($a['related_rating_lean'] ?? [], static fn ($x) => $x >= 0.05));
        $relatedDown = array_keys(array_filter($a['related_rating_lean'] ?? [], static fn ($x) => $x <= -0.05));
        if ($relatedUp) {
            $out[] = ['kind' => 'rating', 'text' => 'Your ratings of related genres favour ' . implode(', ', array_slice($relatedUp, 0, 2)), 'facts' => []];
        }
        if ($relatedDown) {
            $out[] = ['kind' => 'rating', 'text' => 'Your ratings of related genres are lower for ' . implode(', ', array_slice($relatedDown, 0, 2)) . ' - counts against it', 'facts' => []];
        }
        if ($a['signals']['novelty'] >= 0.3 && $novelTags) {
            $novel = array_slice($novelTags, 0, 3);
            $out[] = ['kind' => 'novelty', 'text' => 'New territory for you: ' . implode(', ', $novel), 'facts' => $factsFor($novel)];
        }
        if ($ev['danish'] && (float) $this->config['weights']['danish'] > 0) {
            $out[] = ['kind' => 'danish', 'text' => 'Danish artist', 'facts' => $ev['danish_facts']];
        }
        if ($ev['begin'] !== null && $ev['begin'] >= (int) date('Y') - (int) $this->config['wildcards']['new_artist_years']) {
            $out[] = ['kind' => 'new_artist', 'text' => "New artist (active since {$ev['begin']})", 'facts' => $ev['begin_facts']];
        }
        if ($listened !== null && !$listened['certain']) {
            $out[] = ['kind' => 'listened', 'text' => $listened['text'], 'facts' => []];
        }
        $info = $a['genre_information'];
        if (($a['no_genre'] ?? false)) {
            // Said once, in the release's own caveat (no genre information found).
        } elseif ($a['signals']['tag_confidence'] < 1.0 && $info['amount'] < $this->taste->fullInformation()) {
            $out[] = ['kind' => 'caveat', 'text' => $info['specific'] === []
                ? 'Limited music data: only broad genres (' . implode(', ', $info['broad']) . ')'
                : 'Limited music data: only ' . count($info['specific']) . ' specific genre tag(s)', 'facts' => []];
        } elseif (($r['metadata_quality'] ?? '') === 'partial') {
            $out[] = ['kind' => 'caveat', 'text' => 'Limited music data: tags only, from a single source', 'facts' => []];
        }
        if (strlen((string) ($r['first_release_date'] ?? '')) < 10) {
            $out[] = ['kind' => 'caveat', 'text' => "Release date uncertain (only \"{$r['first_release_date']}\" is known)", 'facts' => []];
        }
        return $out;
    }

    /**
     * How much genre information a release's tags carry (for data confidence):
     * a specific genre counts 1, a broad one ("rock", "hip hop") as much as in
     * the similarity (generic_weight), and a tag contained in a more specific
     * one ("hardcore" next to "beatdown hardcore", "punk" next to "punk rock")
     * adds nothing - it says the same again. Spelling variants are already one
     * tag (TagClassifier::canonical).
     *
     * @param list<string> $tags  canonical genre tags
     * @return array{amount: float, specific: list<string>, broad: list<string>}
     */
    /** "beatdown hardcore" contains "hardcore" (all its words, and more). */
    private static function contains(string $tag, string $part): bool
    {
        $words = static fn (string $t) => array_values(array_filter(preg_split('/[\s\/-]+/', $t) ?: []));
        return count($words($tag)) > count($words($part)) && array_diff($words($part), $words($tag)) === [];
    }

    private function genreInformation(array $tags): array
    {
        $out = ['amount' => 0.0, 'specific' => [], 'broad' => []];
        foreach ($tags as $i => $t) {
            foreach ($tags as $j => $other) {
                if ($i !== $j && self::contains($other, $t)) {
                    continue 2; // contained in a more specific tag
                }
            }
            $out['amount'] += $this->taste->specificity($t);
            $out[$this->taste->isSpecific($t) ? 'specific' : 'broad'][] = $t;
        }
        $out['amount'] = round($out['amount'], 2);
        return $out;
    }

    /**
     * Worth checking out because you have listened to the artist - not because
     * the music is a strong match. Uses the existing artist-interest signal
     * (plays, how recent, how sure the match) and existing limits only:
     *  - the artist is "Listened" (interest >= listened_min), matched with
     *    certainty (not by name only), and not rated 1-2 stars by you here
     *  - a minimal musical anchor: genre data (confidence >= interest.listened_min_confidence)
     *    and not mostly outside your taste (wildcards.max_outside)
     *  - curiosity = fit + assist_weight x interest reaches a wildcard's min_fit:
     *    strong, recent listening can carry a weak match, a faint one cannot
     */
    private function listenedBet(string $id, array $a, array $r): bool
    {
        $c = $this->config;
        if (!($c['interest']['listened_wildcards'] ?? false) || $a['interest_level'] !== 'listened' || !$a['assessable'] || ($a['no_genre'] ?? false) || $this->ratedLow($id)
            || $a['confidence'] < $c['interest']['listened_min_confidence'] || $a['signals']['outside'] > $c['wildcards']['max_outside']) {
            return false;
        }
        $certain = array_filter($r['interest']['artists'] ?? [], static fn ($x) => $x['match'] !== 'name_only' && $x['value'] > 0) !== [];
        return $certain && $a['fit'] + $c['interest']['assist_weight'] * $a['interest'] >= $c['wildcards']['min_fit'];
    }

    /**
     * Too little music data for a recommendation (data confidence below
     * recommended.min_confidence), yet worth a look: a specific genre you
     * listen to, not mostly outside your taste, a fit that would make a bet -
     * and a concrete reason beyond the tags: Last.fm links it to an artist in
     * your profile (wildcards.min_neighbour_bridge) or you rated the artist
     * well. Sparse data alone never makes a wildcard.
     */
    private function limitedDataBet(string $id, array $a): bool
    {
        $c = $this->config;
        if (!$a['assessable'] || $this->ratedLow($id) || $a['confidence'] >= $c['recommended']['min_confidence']
            || $a['specific_shared'] === [] || $a['signals']['outside'] > $c['wildcards']['max_outside'] || $a['fit'] < $c['wildcards']['min_fit']) {
            return false;
        }
        $link = $a['lastfm_artists'] ? max(array_column($a['lastfm_artists'], 'link')) : 0.0;
        return $link >= $c['wildcards']['min_neighbour_bridge'] || ($a['rating_parts']['artist'] ?? 0.0) > 0;
    }

    /** Wildcard angle from the signals - what kind of challenge it is. */
    private function angle(array $a): string
    {
        return match (true) {
            $a['unrelated_blend'] !== null => 'genre-blend',
            ($a['new_artist_since'] ?? null) !== null && $a['new_artist_since'] >= (int) date('Y') - (int) $this->config['wildcards']['new_artist_years'] => 'new-band',
            $a['signals']['outside'] < 0.15 && $a['signals']['novelty'] >= 0.3 => 'neighbouring-genre',
            default => 'left-field',
        };
    }

    /**
     * Reasons for a release as the engine words them now - used to refresh
     * the texts of an earlier run's picks without changing the selection.
     *
     * @return array{fit: float, reasons: list<array{kind: string, text: string, facts: list<string>}>}
     */
    public function explain(array $r, string $type, string $origin): array
    {
        $a = $this->assess($r);
        return ['fit' => $a['fit'], 'reasons' => $this->pick($r['id'], $a, $type, $origin, $r)['reasons']];
    }

    /** "New release from X, one of the artists you play most right now" - from your listening history. */
    private function knownReason(array $r, string $origin): string
    {
        // The credited artist that made the release known (in a collaboration
        // not necessarily the first one).
        $mbid = $r['watch_artist']['mbid'] ?? $r['artist']['mbid'] ?? '';
        $listened = $this->taste->listening[$mbid] ?? null;
        $name = $listened['name'] ?? $r['watch_artist']['name'] ?? ($r['artist']['name'] ?? 'this artist');
        if ($origin === 'following') {
            return "You follow $name - new releases are always included";
        }
        $how = [
            'core' => 'one of your core artists',
            'current' => 'one of the artists you play most right now',
            'recent' => 'an artist you have played recently',
            'dormant' => 'an artist you used to play a lot',
        ][$listened['layer'] ?? ''] ?? null;
        return $how !== null
            ? "New release from $name, $how on Last.fm"
            : "New release from $name, an artist in your Last.fm listening history";
    }

    /**
     * @param string $origin following | interested | discovery
     */
    /**
     * @param string|null $angle  a wildcard's angle when it is decided by the rule
     *                            that picked it ("known-artist"), else from the signals
     */
    private function pick(string $id, array $a, string $type, string $origin, array $r, ?string $angle = null): array
    {
        $reasons = array_values(array_filter($a['reasons'], static fn ($x) => $x['kind'] !== 'known'));
        if ($origin !== 'discovery') {
            array_unshift($reasons, ['kind' => 'known', 'text' => $this->knownReason($r, $origin), 'facts' => []]);
        }
        if (!$a['assessable'] || ($a['no_genre'] ?? false)) {
            $reasons[] = ['kind' => 'caveat', 'text' => 'Limited data: no genre information found', 'facts' => []];
        }
        if ($type === 'wildcard' && $a['unrelated_blend'] !== null) {
            [$f1, $f2] = $a['unrelated_blend'];
            array_unshift($reasons, ['kind' => 'blend', 'text' => "An unusual mix: $f1 with $f2", 'facts' => []]);
        } elseif ($type === 'wildcard' && $a['new_family_share'] >= $this->config['wildcards']['min_new_family_share']) {
            $fams = array_keys(array_filter($a['families'], fn ($sh, $f) => $this->taste->familyWeight($f) < $this->config['wildcards']['novel_family_max'], ARRAY_FILTER_USE_BOTH));
            array_unshift($reasons, ['kind' => 'novelty', 'text' => 'A genre family you rarely play: ' . implode(', ', array_slice($fams, 0, 2)), 'facts' => []]);
        }
        $limited = !$a['assessable'] || array_filter($reasons, static fn ($x) => $x['kind'] === 'caveat' && str_starts_with($x['text'], 'Limited data')) !== [];
        if ($angle === 'limited-data') {
            // Lead with the concrete reason, and say plainly what we don't know.
            usort($reasons, static fn ($x, $y) => (int) in_array($y['kind'], ['lastfm', 'rating'], true) <=> (int) in_array($x['kind'], ['lastfm', 'rating'], true));
            $reasons = array_values(array_filter($reasons, static fn ($x) => $x['kind'] !== 'caveat'));
            $reasons[] = ['kind' => 'caveat', 'text' => 'Limited music data - a cautious tip, not a strong match', 'facts' => []];
        } elseif ($angle === 'known-artist') {
            // Lead with why: you know the artist - and say plainly it is not a strong match.
            $lead = ArtistInterest::reason($r['interest'] ?? [])['text'] ?? "You've listened to this artist before";
            $reasons = array_values(array_filter($reasons, static fn ($x) => $x['kind'] !== 'listened'));
            array_unshift($reasons, ['kind' => 'listened', 'text' => "$lead - not a strong match with your taste, but worth a listen", 'facts' => []]);
        } elseif ($type === 'wildcard') {
            // Lead with what is new, then the bridge back to your taste.
            usort($reasons, static fn ($x, $y) => (int) ($y['kind'] === 'novelty' || $y['kind'] === 'blend') <=> (int) ($x['kind'] === 'novelty' || $x['kind'] === 'blend'));
        }
        $short = implode('. ', array_column(array_slice(array_filter($reasons, static fn ($x) => $x['kind'] !== 'caveat'), 0, 2), 'text'));
        return [
            'id' => $id,
            'type' => $type,
            'origin' => $origin,
            'forced' => $origin !== 'discovery',
            'data_quality' => $limited ? 'limited' : 'good',
            // "same kind of record" for ordering: lead artist link, else family
            'cluster' => array_key_first($a['tag_artists'] ?? []) ?? array_key_first($a['lastfm_artists'] ?? []) ?? ($a['primary_family'] ?? '-'),
            'why' => $short,
            'angle' => $type === 'wildcard' ? ($angle ?? $this->angle($a)) : null,
            'reasons' => $reasons,
            'fit' => $a['fit'],
            'confidence' => $a['confidence'],
            'signals' => $a['signals'],
            'tag_artists' => $a['tag_artists'] ?? [],
            'lastfm_artists' => $a['lastfm_artists'] ?? [],
            'score_parts' => $a['score_parts'] ?? [],
            // Artist interest - kept apart from the score (fit).
            'interest_level' => $a['interest_level'] ?? 'unknown',
            'interest' => $a['interest'] ?? 0.0,
            'interest_assisted' => false,
        ];
    }

    /**
     * Documented evidence from the release's facts: genre tags with the fact
     * ids they came from, Danish origin, artist start year.
     */
    public function evidence(array $r): array
    {
        $tags = [];
        $meta = [];
        $danishFacts = [];
        $begin = null;
        $beginFacts = [];
        $sw = $this->config['source_weights'];
        // Tags that just repeat the artist name or title are not genres.
        $nameKeys = TagClassifier::key((string) ($r['artist']['primary_name'] ?? '')) . '|' . TagClassifier::key((string) ($r['title'] ?? ''));

        foreach ($r['facts'] ?? [] as $f) {
            if (preg_match('/^(.+?): (.*)$/', $f['text'], $m) && isset(self::TAG_FACTS[$m[1]])) {
                $list = array_map('trim', explode(',', $m[2]));
                $positional = $m[1] === 'Artist tags on Last.fm (strongest first)';
                foreach ($list as $pos => $raw) {
                    if ($raw === '' || TagClassifier::classify($raw) !== 'genre') {
                        continue;
                    }
                    $t = TagClassifier::canonical($raw);
                    $k = TagClassifier::key($raw);
                    if (strlen($k) >= 4 && str_contains($nameKeys, $k) && TagClassifier::family($t) === 'Other') {
                        continue;
                    }
                    $weight =($sw[$f['source']] ?? 1.0) * ($positional ? max(0.4, 1 - 0.08 * $pos) : 1.0);
                    $tags[$t]['weight'] = ($tags[$t]['weight'] ?? 0) + $weight;
                    $tags[$t]['facts'][] = $f['id'];
                }
            } elseif (str_starts_with($f['text'], 'Other Last.fm artist tags: ')) {
                $m2 = array_map('trim', explode(',', substr($f['text'], 27)));
                $meta = [...$meta, ...$m2];
                if (array_intersect(array_map(TagClassifier::key(...), $m2), ['danish', 'dansk', 'denmark'])) {
                    $danishFacts[] = $f['id'];
                }
            } elseif ($f['source'] === 'musicbrainz:artist' && str_starts_with($f['text'], 'Artist ')) {
                if (preg_match('/active since (\d{4})/', $f['text'], $mm)) {
                    $begin = (int) $mm[1];
                    $beginFacts[] = $f['id'];
                }
                if (preg_match('/from (Denmark|Copenhagen|Aarhus|Odense|Aalborg)\b/i', $f['text'])) {
                    $danishFacts[] = $f['id'];
                }
            }
        }
        if (($r['country'] ?? null) === 'DK' && $danishFacts === []) {
            $danishFacts = array_values(array_map(static fn ($f) => $f['id'], array_filter($r['facts'] ?? [], static fn ($f) => $f['source'] === 'musicbrainz:artist')));
        }

        return [
            'tags' => $tags,
            'meta' => $meta,
            'danish' => ($r['country'] ?? null) === 'DK' || $danishFacts !== [],
            'danish_facts' => array_values(array_unique($danishFacts)),
            'begin' => $begin,
            'begin_facts' => $beginFacts,
        ];
    }
}
