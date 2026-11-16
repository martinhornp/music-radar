<?php

declare(strict_types=1);

namespace MusicRadar\Recommend;

use MusicRadar\Taste\TagClassifier;

/**
 * The listener's taste as numbers the engine can compare against:
 *   - a weighted genre vector (from the layered music profile)
 *   - genre-family weights
 *   - one tag vector per known artist, with its listening-layer weight
 *     (used for "close to artists you listen to")
 *   - which family combinations already occur within single artists
 *   - Danish share
 * Built only from the music profile (Last.fm listening + tags, MusicBrainz).
 */
final class TasteModel
{
    /**
     * @param array<string, float> $genres   canonical tag => weight
     * @param array<string, float> $families family => weight
     * @param list<array{name: string, layer: string, weight: float, vector: array<string, float>, families: list<string>}> $artists
     * @param array<string, true> $familyPairs  "A|B" pairs seen within one artist
     * @param array<string, array{name: string, layer: string}> $listening  every profile artist by MusicBrainz id (for wording only)
     */
    public function __construct(
        public readonly array $genres,
        public readonly array $families,
        public readonly array $artists,
        public readonly array $familyPairs,
        public readonly float $danishShare,
        private readonly array $config,
        public readonly array $listening = [],
    ) {
    }

    public static function fromProfile(array $profile, array $config): self
    {
        // The stored profile is re-classified with today's rules (no API calls):
        // tags that are not genres (noise, properties, unknown) drop out and
        // spelling variants merge - whenever the profile was built. Families
        // are the sum of their genres, so they are recalculated the same way.
        $genres = [];
        foreach ($profile['genres'] as $g) {
            if (TagClassifier::classify((string) $g['name']) === 'genre') {
                $name = TagClassifier::canonical((string) $g['name']);
                $genres[$name] = ($genres[$name] ?? 0.0) + (float) $g['weight'];
            }
        }
        arsort($genres);
        $families = [];
        foreach ($genres as $name => $w) {
            $f = TagClassifier::family($name);
            $families[$f] = ($families[$f] ?? 0.0) + $w;
        }
        arsort($families);

        $artists = [];
        $pairs = [];
        $listening = [];
        foreach ($profile['artists'] as $a) {
            if ($mbid = $a['musicbrainz']['mbid'] ?? null) {
                $listening[$mbid] = ['name' => (string) $a['name'], 'layer' => (string) ($a['lastfm']['listening'] ?? '')];
            }
            $weights = $a['genres']['weights'] ?? [];
            // Interested-only artists (outside ProfileBuilder::TASTE_TOP) are not part of the musical taste.
            if ($weights === [] || ($a['lastfm']['taste'] ?? true) === false) {
                continue;
            }
            $vector = [];
            foreach ($weights as $tag => $w) {
                if (TagClassifier::classify((string) $tag) === 'genre') {
                    $name = TagClassifier::canonical((string) $tag);
                    $vector[$name] = ($vector[$name] ?? 0.0) + (float) $w;
                }
            }
            if ($vector === []) {
                continue;
            }
            $fams = array_keys(array_filter($a['genres']['families'] ?? [], static fn ($w) => $w >= 0.15));
            sort($fams);
            for ($i = 0; $i < count($fams); $i++) {
                for ($j = $i + 1; $j < count($fams); $j++) {
                    $pairs["{$fams[$i]}|{$fams[$j]}"] = true;
                }
            }
            $layer = (string) $a['lastfm']['listening'];
            $artists[] = [
                'name' => (string) $a['name'],
                'layer' => $layer,
                'weight' => (float) ($config['layer_weights'][$layer] ?? 0.5),
                'mbid' => ($a['musicbrainz']['mbid'] ?? null) ?: null,
                'play_weight' => (float) ($a['lastfm']['play_weight'] ?? 1.0),
                'vector' => $vector,
                'families' => array_keys($a['genres']['families'] ?? []),
            ];
        }

        return new self($genres, $families, $artists, $pairs, (float) ($profile['attributes']['danish_share'] ?? 0), $config, $listening);
    }

    public function familyWeight(string $family): float
    {
        return $this->families[$family] ?? 0.0;
    }

    public function maxFamilyWeight(): float
    {
        return $this->families ? max($this->families) : 1.0;
    }

    public function isLikedFamily(string $family): bool
    {
        return $this->familyWeight($family) >= (float) $this->config['liked_family_min'];
    }

    /** Liked family, or closely related to one. */
    public function isNearTaste(string $family): bool
    {
        if ($this->isLikedFamily($family)) {
            return true;
        }
        foreach ($this->config['related_families'][$family] ?? [] as $rel) {
            if ($this->isLikedFamily($rel)) {
                return true;
            }
        }
        return false;
    }

    /** Two families that are close relatives (hardcore + metalcore). */
    public function areRelated(string $a, string $b): bool
    {
        $rel = $this->config['related_families'];
        return $a === $b || in_array($b, $rel[$a] ?? [], true) || in_array($a, $rel[$b] ?? [], true);
    }

    /** @var array{info: array<string, float>, default: float, full: float}|null see TagInformation */
    private ?array $tagInformation = null;

    /** Information per genre tag, learned from the releases being judged (TagInformation). */
    public function useTagInformation(array $tagInformation): void
    {
        $this->tagInformation = $tagInformation;
    }

    /**
     * How much a tag tells about the music (0..1): learned from the releases
     * (TagInformation) when available, else the short broad-tag list.
     */
    public function specificity(string $tag): float
    {
        if ($this->tagInformation !== null) {
            // Known tags are capped already; an unseen tag gets the default, capped once.
            return $this->tagInformation['info'][$tag]
                ??= min(TagInformation::cap($tag, $this->config), $this->tagInformation['default']);
        }
        return in_array($tag, $this->config['generic_tags'], true) ? (float) $this->config['generic_weight'] : 1.0;
    }

    /** A specific (not broad) genre tag. */
    public function isSpecific(string $tag): bool
    {
        return $this->specificity($tag) >= ($this->tagInformation !== null ? (float) $this->config['model']['specific_min'] : 1.0);
    }

    /** Genre information that counts as "full" (two typical specific tags). */
    public function fullInformation(): float
    {
        return (float) ($this->tagInformation['full'] ?? 2.0);
    }

    /**
     * Cosine similarity of two tag vectors, with generic tags down-weighted.
     *
     * @return array{float, array<string, float>} similarity and per-tag contribution
     */
    public function similarity(array $a, array $b): array
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        $contrib = [];
        foreach ($a as $t => $w) {
            $wa = $w * $this->specificity($t);
            $na += $wa * $wa;
            if (isset($b[$t])) {
                $wb = $b[$t] * $this->specificity($t);
                $dot += $wa * $wb;
                $contrib[$t] = $wa * $wb;
            }
        }
        foreach ($b as $t => $w) {
            $wb = $w * $this->specificity($t);
            $nb += $wb * $wb;
        }
        if ($dot <= 0 || $na <= 0 || $nb <= 0) {
            return [0.0, []];
        }
        arsort($contrib);
        return [$dot / sqrt($na * $nb), $contrib];
    }
}
