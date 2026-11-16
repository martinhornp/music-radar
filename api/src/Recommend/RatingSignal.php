<?php

declare(strict_types=1);

namespace MusicRadar\Recommend;

use MusicRadar\Taste\TagClassifier;

/**
 * What your own star ratings say about a new release, each part "shrunk"
 * toward neutral so that a single rating only nudges and the effect grows
 * as consistent ratings accumulate:
 *
 *   artist   ratings of other releases by the same artist (MusicBrainz id).
 *            The strongest evidence we have: it can tell two metalcore bands
 *            apart, which genre tags cannot. Added to the score.
 *   tag      tagPreference(): per genre tag, sum of rating signals / (ratings
 *            with the tag + tag_prior). Not added on top: the engine uses it to
 *            adjust how much you like that tag INSIDE the genre signal, so the
 *            same tags are never rewarded twice. One 5-star rating moves a tag
 *            by 1/11; five consistent ones by 1/3 - one rating mainly says
 *            something about the artist, not about a whole genre.
 *   related  relatedPreference(): for a sub-genre you have not rated, your
 *            ratings of releases with a RELATED specific tag of the same
 *            family. How related: the share of the releases with the
 *            sub-genre that also carry the rated tag (learnRelations()) -
 *            youth crew releases are mostly tagged hardcore punk as well, drill
 *            releases rarely alternative hip hop. Shrunk by related_prior
 *            (like tag_prior; the evidence is indirect). The engine uses half
 *            of it only for a specific sub-genre that is neither in your
 *            profile nor rated itself - as such a tag gets half of a liked
 *            family (model.family_fallback).
 *   similar, genre (older parts, off by default: weight 0) - rated releases
 *            with similar tags, and a per-tag average added to the score. Both
 *            repeat the genre tags the genre signal already counts.
 *
 * Limits, stated honestly: the similarity is based on genre tags only. Two
 * releases with the same tags look identical here, however different they
 * sound. Ratings never make the engine claim to know more than the tags say.
 *
 * "Not interested" and "Save for later" are not ratings and never get here.
 * Ratings come with a snapshot of the rated release (tags, artist id), so
 * they keep counting after the release has left the feed.
 */
final class RatingSignal
{
    public const DEFAULTS = [
        // Stars -> signal. 3 stars = listened and it was fine: barely positive.
        'stars' => [1 => -1.0, 2 => -0.5, 3 => 0.1, 4 => 0.5, 5 => 1.0],
        'artist_weight' => 0.20,
        'artist_prior' => 1,        // imaginary neutral ratings per artist
        'similar_weight' => 0.25,
        'similar_prior' => 4.0,     // imaginary neutral similarity mass
        'similar_min' => 0.5,       // only clearly similar releases count
        'genre_weight' => 0.10,
        'genre_prior' => 5,         // imaginary neutral ratings per tag
        'tag_prior' => 10,          // imaginary neutral ratings per tag (tagPreference)
        'related_prior' => 10,      // imaginary neutral ratings per sub-genre (relatedPreference)
    ];

    /** @var list<array{release_id: ?string, artist_mbid: ?string, artist: string, title: string, vector: array<string, float>, stars: int, signal: float}> */
    private array $ratings = [];
    /** @var array<string, list<array{string, float}>> tag => [release id, signal] */
    private array $byTag = [];
    /** @var array<string, int> tag => releases with it (learnRelations) */
    private array $tagReleases = [];
    /** @var array<string, array<string, int>> tag => rated tag => releases with both */
    private array $withRated = [];
    private array $cfg;

    /**
     * @param list<array{tags: list<string>, stars: int, release_id?: string, artist_mbid?: ?string, artist?: string, title?: string}> $ratings
     */
    public function __construct(private readonly TasteModel $taste, array $ratings, array $config = [])
    {
        $this->cfg = $config + self::DEFAULTS;
        foreach ($ratings as $r) {
            $stars = (int) $r['stars'];
            if ($stars < 1 || $stars > 5) {
                continue;
            }
            $vector = [];
            foreach ($r['tags'] as $t) {
                $vector[TagClassifier::canonical((string) $t)] = 1.0;
            }
            $signal = (float) $this->cfg['stars'][$stars];
            $this->ratings[] = [
                'release_id' => $r['release_id'] ?? null,
                'artist_mbid' => ($r['artist_mbid'] ?? null) ?: null,
                'artist' => (string) ($r['artist'] ?? ''),
                'title' => (string) ($r['title'] ?? ''),
                'vector' => $vector,
                'stars' => $stars,
                'signal' => $signal,
                'not_interested' => (bool) ($r['not_interested'] ?? false),
            ];
            foreach ($vector as $t => $_) {
                $this->byTag[$t][] = [(string) ($r['release_id'] ?? ''), $signal, ($r['artist_mbid'] ?? null) ?: null];
            }
        }
    }

    /**
     * Your ratings of one artist, shrunk toward neutral exactly as the artist
     * part of assess() (-1 .. 1, 0 without ratings).
     */
    public function artistLean(?string $mbid): float
    {
        if ($mbid === null) {
            return 0.0;
        }
        $signals = array_column(array_filter($this->ratings, static fn ($r) => $r['artist_mbid'] === $mbid), 'signal');
        return $signals ? array_sum($signals) / (count($signals) + $this->cfg['artist_prior']) : 0.0;
    }

    public function count(): int
    {
        return count($this->ratings);
    }

    /**
     * @param array<string, float> $vector the release's tag vector
     * @return array{value: float, artist: float, similar: float, genre: float, evidence: list<array{kind: string, text: string, facts: list<string>}>}
     */
    public function assess(array $vector, ?string $artistMbid, ?string $releaseId): array
    {
        $none = ['value' => 0.0, 'artist' => 0.0, 'similar' => 0.0, 'genre' => 0.0, 'evidence' => []];
        if ($this->ratings === []) {
            return $none;
        }
        $c = $this->cfg;
        $sameArtist = [];
        $similar = [];
        foreach ($this->ratings as $r) {
            if ($releaseId !== null && $r['release_id'] === $releaseId) {
                continue; // a rating explains other releases, not itself
            }
            if ($artistMbid !== null && $r['artist_mbid'] === $artistMbid) {
                $sameArtist[] = $r;
                continue;
            }
            if ($c['similar_weight'] > 0 && $vector !== [] && $r['vector'] !== []) {
                [$sim] = $this->taste->similarity($vector, $r['vector']);
                if ($sim >= $c['similar_min']) {
                    $similar[] = $r + ['sim' => $sim];
                }
            }
        }

        $artist = $sameArtist ? array_sum(array_column($sameArtist, 'signal')) / (count($sameArtist) + $c['artist_prior']) : 0.0;

        $simMass = array_sum(array_column($similar, 'sim'));
        $similarValue = $similar
            ? array_sum(array_map(static fn ($r) => $r['sim'] * $r['signal'], $similar)) / ($simMass + $c['similar_prior'])
            : 0.0;

        $tagSignals = [];
        foreach (array_intersect_key($this->byTag, $vector) as $t => $entries) {
            $signals = array_column(array_filter($entries, static fn ($e) => $releaseId === null || $e[0] !== $releaseId), 1);
            if ($signals) {
                $tagSignals[] = array_sum($signals) / (count($signals) + $c['genre_prior']);
            }
        }
        $genre = $tagSignals ? array_sum($tagSignals) / count($tagSignals) : 0.0;

        $value = $c['artist_weight'] * $artist + $c['similar_weight'] * $similarValue + $c['genre_weight'] * $genre;

        return [
            'value' => round($value, 4),
            'artist' => round($artist, 3),
            'similar' => round($similarValue, 3),
            'genre' => round($genre, 3),
            'evidence' => $this->evidence($sameArtist, $similar),
        ];
    }

    /**
     * Your ratings' lean on one genre tag, -1..1 (0 without ratings): shrunk
     * toward neutral, the release's own rating left out.
     */
    public function tagPreference(string $tag, ?string $releaseId, ?string $artistMbid = null): float
    {
        $signals = array_column(array_filter($this->byTag[$tag] ?? [], static fn ($e) => self::other($e[0], $e[2], $releaseId, $artistMbid)), 1);
        return $signals ? array_sum($signals) / (count($signals) + (float) $this->cfg['tag_prior']) : 0.0;
    }

    /** Whether you rated another release with this genre tag. */
    public function ratedTag(string $tag, ?string $releaseId, ?string $artistMbid = null): bool
    {
        foreach ($this->byTag[$tag] ?? [] as $e) {
            if (self::other($e[0], $e[2], $releaseId, $artistMbid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Learns which tags go together from the releases being judged: for every
     * tag, on how many releases it is, and how many of those also carry each
     * tag you have rated.
     *
     * @param list<array<string, float>> $vectors one tag => weight vector per release
     */
    public function learnRelations(array $vectors): void
    {
        $this->tagReleases = [];
        $this->withRated = [];
        foreach ($vectors as $v) {
            $rated = array_intersect_key($v, $this->byTag);
            foreach ($v as $t => $_) {
                $this->tagReleases[$t] = ($this->tagReleases[$t] ?? 0) + 1;
                foreach ($rated as $rt => $__) {
                    if ($rt !== $t) {
                        $this->withRated[$t][$rt] = ($this->withRated[$t][$rt] ?? 0) + 1;
                    }
                }
            }
        }
    }

    /**
     * Your ratings' lean on a sub-genre you have not rated, -1..1: each rating
     * counts by how related its closest specific tag of the same family is
     * (share of the sub-genre's releases that also carry that tag, shrunk by
     * one imaginary release without it), the sum shrunk toward neutral by
     * related_prior, the release's own rating left out. 0 without
     * learnRelations() or related ratings.
     */
    public function relatedPreference(string $tag, ?string $releaseId, ?string $artistMbid = null): float
    {
        $n = $this->tagReleases[$tag] ?? 0;
        if ($n === 0 || !isset($this->withRated[$tag])) {
            return 0.0;
        }
        $family = TagClassifier::family($tag);
        $sum = 0.0;
        $mass = 0.0;
        foreach ($this->ratings as $r) {
            if (!self::other($r['release_id'], $r['artist_mbid'], $releaseId, $artistMbid)) {
                continue;
            }
            $w = 0.0;
            foreach (array_intersect_key($this->withRated[$tag], $r['vector']) as $rt => $both) {
                if (TagClassifier::family($rt) === $family && $this->taste->isSpecific($rt)) {
                    $w = max($w, $both / ($n + 1));
                }
            }
            $sum += $w * $r['signal'];
            $mass += $w;
        }
        return $mass > 0 ? $sum / ($mass + (float) $this->cfg['related_prior']) : 0.0;
    }

    /**
     * Your own opinion of one artist from your ratings of its other releases
     * (Not interested counts as 1 star): how many, and their mean on 0..1
     * (1 star = 0, 3 stars = 0.5, 5 stars = 1).
     *
     * @return array{count: int, mean: float}
     */
    public function artistOpinion(?string $artistMbid, ?string $releaseId): array
    {
        $stars = [];
        foreach ($artistMbid === null ? [] : $this->ratings as $r) {
            if ($r['artist_mbid'] === $artistMbid && ($releaseId === null || $r['release_id'] !== $releaseId)) {
                $stars[] = ($r['stars'] - 1) / 4;
            }
        }
        return ['count' => count($stars), 'mean' => $stars ? array_sum($stars) / count($stars) : 0.0];
    }

    /**
     * A rating that may teach the genre signal of this release: not the
     * release itself, and not another release by the same artist - your
     * opinion of the artist counts once, in the engine's personal part.
     */
    private static function other(?string $ratedRelease, ?string $ratedArtist, ?string $releaseId, ?string $artistMbid): bool
    {
        return ($releaseId === null || $ratedRelease !== $releaseId) && ($artistMbid === null || $ratedArtist !== $artistMbid);
    }

    /** Reasons that name the concrete ratings behind the signal. */
    private function evidence(array $sameArtist, array $similar): array
    {
        $out = [];
        $stars = static fn (int $n) => $n . ' star' . ($n === 1 ? '' : 's');
        $against = static fn (array $r) => $r['signal'] < 0 ? ' - counts against it' : '';
        if ($sameArtist) {
            usort($sameArtist, static fn ($a, $b) => abs($b['signal']) <=> abs($a['signal']));
            $r = $sameArtist[0];
            $more = count($sameArtist) > 1 ? ' (and ' . (count($sameArtist) - 1) . ' more by them)' : '';
            $did = $r['not_interested'] ? 'marked' : 'rated';
            $what = $r['not_interested'] ? 'Not interested' : $stars($r['stars']);
            $out[] = ['kind' => 'rating', 'text' => "You $did {$r['title']} by {$r['artist']} $what$more{$against($r)}", 'facts' => []];
        }
        if ($similar) {
            usort($similar, static fn ($a, $b) => $b['sim'] <=> $a['sim']);
            $r = $similar[0];
            $out[] = ['kind' => 'rating', 'text' => "Genre tags similar to {$r['title']} by {$r['artist']}, which you rated {$stars($r['stars'])}{$against($r)}", 'facts' => []];
        }
        return $out;
    }
}
