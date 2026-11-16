<?php

declare(strict_types=1);

namespace MusicRadar\Taste;

/**
 * The result of identifying a Last.fm artist in MusicBrainz.
 *
 * Statuses:
 *   confirmed     one MusicBrainz artist, backed by independent evidence
 *   needs_review  candidates exist, but we won't pick one without a human
 *   not_found     no MusicBrainz artist with this name
 *   manual        set by hand in api/data/artist-overrides.php
 *   manual_none   confirmed by hand that there is no MusicBrainz entry
 */
final class ArtistMatch
{
    public const CONFIRMED = 'confirmed';
    public const NEEDS_REVIEW = 'needs_review';
    public const NOT_FOUND = 'not_found';
    public const MANUAL = 'manual';
    public const MANUAL_NONE = 'manual_none';

    /**
     * @param 'high'|'medium'|'low'|'none' $confidence
     * @param list<string> $evidence  why we believe the match
     * @param list<string> $problems  why it is uncertain
     * @param list<array<string, mixed>> $candidates  alternatives for a human to choose from
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $mbid,
        public readonly string $confidence,
        public readonly string $source,
        public readonly array $evidence = [],
        public readonly array $problems = [],
        public readonly array $candidates = [],
        public readonly ?string $mbName = null,
        public readonly ?string $country = null,
        public readonly ?string $disambiguation = null,
        /** @var list<string> MusicBrainz tags/genres of the chosen artist */
        public readonly array $mbTags = [],
    ) {
    }

    public function isUsable(): bool
    {
        return $this->mbid !== null && in_array($this->status, [self::CONFIRMED, self::MANUAL], true);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'mbid' => $this->mbid,
            'musicbrainz_name' => $this->mbName,
            'country' => $this->country,
            'disambiguation' => $this->disambiguation,
            'musicbrainz_tags' => $this->mbTags,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'evidence' => $this->evidence,
            'problems' => $this->problems,
            'candidates' => $this->candidates,
        ];
    }
}
