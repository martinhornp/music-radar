<?php

declare(strict_types=1);

namespace MusicRadar\Taste;

use MusicRadar\Recommend\ArtistInterest;

/**
 * How many artists are on each level, counted from what is stored (for the
 * internal status - read only, nothing is changed):
 *
 *   following / interested  watch.level of each artist in the stored music
 *                           profile (the latest row in `profiles`)
 *   taste_artists           artists in the musical taste profile (lastfm.taste;
 *                           profiles from before the flag held only taste artists)
 *   top100_unique           artists with a stored Last.fm rank in any period
 *   listened                artists in the stored Last.fm library
 *                           (`listening_artists`) that are not Following or
 *                           Interested and whose plays reach the "Listened"
 *                           value. Listened is not stored per artist: the engine
 *                           decides it per release, also from how sure the match
 *                           is - so this counts the artists that can make a
 *                           release "Listened" when matched with certainty
 *                           (name-only library entries count half there).
 *
 * Check: every artist with a current top-100 rank should be Interested (or
 * Following); any other stored level is reported with name and MusicBrainz id.
 */
final class ArtistLevels
{
    private const WATCHED = [FollowPolicy::FOLLOWING, FollowPolicy::INTERESTED];

    /**
     * @param array{id: int, created_at: string, source: string, profile: array}|null $profileRow  DiscoveryStore::latestProfile()
     * @param list<array<string, mixed>> $library  DiscoveryStore::listening()
     */
    public static function summary(?array $profileRow, array $library, array $interestConfig): array
    {
        $artists = $profileRow['profile']['artists'] ?? [];
        $levels = array_count_values(array_map(static fn ($a) => (string) ($a['watch']['level'] ?? 'none'), $artists));
        $top100 = array_filter($artists, static fn ($a) => array_filter($a['lastfm']['periods'] ?? [], static fn ($p) => (int) ($p['rank'] ?? 999) <= 100) !== []);
        $mismatches = array_values(array_map(static fn ($a) => [
            'name' => (string) $a['name'],
            'mbid' => $a['musicbrainz']['mbid'] ?? null,
            'level' => $a['watch']['level'] ?? null,
            'source' => $a['watch']['source'] ?? null,
        ], array_filter($top100, static fn ($a) => !in_array($a['watch']['level'] ?? null, self::WATCHED, true))));

        // Library artists that are already Following/Interested are not "Listened".
        $watchedIds = [];
        $watchedNames = [];
        foreach ($artists as $a) {
            if (in_array($a['watch']['level'] ?? null, self::WATCHED, true)) {
                $watchedIds[(string) ($a['musicbrainz']['mbid'] ?? '')] = true;
                $watchedNames[ListeningLibrary::key((string) $a['name'])] = true;
            }
        }
        unset($watchedIds['']);
        $interest = new ArtistInterest($profileRow['profile'] ?? [], [], $interestConfig);
        $listened = [];
        foreach ($library as $row) {
            if (isset($watchedIds[$row['mbid']]) || isset($watchedNames[$row['name_key']])) {
                continue;
            }
            if ($interest->playsValue($row['plays']) >= $interest->listenedMin()) {
                $listened[$row['identity']] = ($listened[$row['identity']] ?? 0) + 1;
            }
        }

        return [
            'profile_id' => $profileRow['id'] ?? null,
            // Profiles are only stored after a successful refresh (or upload).
            'profile_updated_at' => $profileRow['created_at'] ?? null,
            'profile_source' => $profileRow['source'] ?? null,
            'profile_artists' => count($artists),
            'following' => $levels[FollowPolicy::FOLLOWING] ?? 0,
            'interested' => $levels[FollowPolicy::INTERESTED] ?? 0,
            'other_levels' => array_diff_key($levels, array_flip([FollowPolicy::FOLLOWING, FollowPolicy::INTERESTED])),
            'listened' => array_sum($listened),
            'listened_by_identity' => $listened,
            'library_artists' => count($library),
            'taste_artists' => count(array_filter($artists, static fn ($a) => ($a['lastfm']['taste'] ?? true) !== false)),
            'top100_unique' => count($top100),
            'top100_check' => ['ok' => $mismatches === [], 'not_interested' => $mismatches],
        ];
    }
}
