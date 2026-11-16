<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

/**
 * Which MusicBrainz tags to search for candidate releases: the music
 * profile's top genres plus a few neighbouring genres, so the pool reaches a
 * bit beyond what is already known.
 */
final class ProfileSummary
{
    public const NEIGHBOUR_TAGS = ['noise rock', 'mathcore', 'screamo', 'emo', 'industrial', 'drum and bass', 'shoegaze', 'grime', 'hyperpop', 'sludge metal'];

    /** @return list<string> */
    public static function searchTags(array $profile, int $top = 14): array
    {
        $tags = array_map(static fn ($g) => $g['name'], array_slice($profile['genres'], 0, $top));
        return array_values(array_unique([...$tags, ...self::NEIGHBOUR_TAGS]));
    }
}
