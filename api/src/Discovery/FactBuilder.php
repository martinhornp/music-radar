<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

use MusicRadar\Taste\TagClassifier;

/**
 * Turns already-fetched data about a release into documented facts (with ids
 * and sources) - no network calls, so the same rules serve the one-off
 * experiment and the incremental pipeline.
 *
 * Last.fm data is used only when it verifiably describes the same artist:
 * Last.fm's MusicBrainz ID matches, or (without an ID) Last.fm knows this
 * exact album under that artist name. Name collisions are common for new and
 * small artists.
 */
final class FactBuilder
{
    private const BIO_CHARS = 500;
    private const DESC_CHARS = 700;

    /**
     * @param array<string, mixed>|null $mbArtist  MusicBrainz artist (lookup or search record)
     * @param array{name: string, mbid: string, bio: string, tags: list<string>}|null $lfArtist
     * @param array{name: string, description: string, tags: list<string>}|null $lfAlbum
     */
    public static function build(array $release, ?array $mbArtist, ?array $lfArtist, ?array $lfAlbum, bool $lastfmChecked = true): array
    {
        $facts = [];
        $issues = [];
        $add = static function (string $text, string $source) use (&$facts): void {
            $facts[] = ['id' => 'F' . (count($facts) + 1), 'text' => $text, 'source' => $source];
        };
        $primary = (string) ($release['artist']['primary_name'] ?? $release['artist']['name']);
        $releaseTags = $release['release_tags'] ?? [];

        $add("{$release['type']} \"{$release['title']}\" by {$release['artist']['name']}, first released {$release['first_release_date']}", 'musicbrainz:release-group');
        if ($releaseTags !== []) {
            $add('Release tags: ' . implode(', ', array_slice($releaseTags, 0, 10)), 'musicbrainz:release-group');
        }

        // --- MusicBrainz artist
        $mbTags = [];
        if ($mbArtist !== null) {
            $bits = array_filter([
                $mbArtist['type'] ?? null,
                ($mbArtist['area']['name'] ?? null) ? 'from ' . $mbArtist['area']['name'] : null,
                ($mbArtist['life-span']['begin'] ?? null) ? 'active since ' . substr($mbArtist['life-span']['begin'], 0, 4) : null,
                ($mbArtist['disambiguation'] ?? '') !== '' ? "described as \"{$mbArtist['disambiguation']}\"" : null,
            ]);
            if ($bits) {
                $add("Artist $primary: " . implode(', ', $bits), 'musicbrainz:artist');
            }
            $tagRows = [...($mbArtist['genres'] ?? []), ...($mbArtist['tags'] ?? [])];
            usort($tagRows, static fn ($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
            $mbTags = array_values(array_unique(array_map(static fn ($t) => (string) $t['name'], $tagRows)));
            if ($mbTags !== []) {
                $add('Artist genres/tags: ' . implode(', ', array_slice($mbTags, 0, 10)), 'musicbrainz:artist');
            }
        }

        // --- Last.fm, only if it is verifiably the same artist
        $lfArtistTags = [];
        $lfAlbumTags = [];
        $lastfmUsed = false;
        if ($lfArtist !== null) {
            $sameByMbid = $lfArtist['mbid'] !== '' && $lfArtist['mbid'] === ($release['artist']['mbid'] ?? '');
            $otherMbid = $lfArtist['mbid'] !== '' && !$sameByMbid;
            if ($sameByMbid || (!$otherMbid && $lfAlbum !== null)) {
                $lastfmUsed = true;
                $genre = [];
                $meta = [];
                foreach ($lfArtist['tags'] as $t) {
                    match (TagClassifier::classify($t, $primary)) {
                        'genre' => $genre[] = TagClassifier::canonical($t),
                        'meta' => $meta[] = TagClassifier::canonical($t),
                        default => null,
                    };
                }
                $lfArtistTags = array_values(array_unique($genre));
                if ($lfArtistTags !== []) {
                    $add('Artist tags on Last.fm (strongest first): ' . implode(', ', array_slice($lfArtistTags, 0, 8)), 'lastfm:artist');
                }
                if ($meta !== []) {
                    $add('Other Last.fm artist tags: ' . implode(', ', array_slice(array_unique($meta), 0, 5)), 'lastfm:artist');
                }
                if ($lfArtist['bio'] !== '') {
                    $add('Artist bio (Last.fm wiki): ' . mb_strimwidth($lfArtist['bio'], 0, self::BIO_CHARS, '…'), 'lastfm:artist-wiki');
                }
                if ($lfAlbum !== null) {
                    $lfAlbumTags = array_values(array_filter($lfAlbum['tags'], static fn ($t) => TagClassifier::classify($t) === 'genre'));
                    if ($lfAlbumTags !== []) {
                        $add('Album tags on Last.fm: ' . implode(', ', $lfAlbumTags), 'lastfm:album');
                    }
                    if ($lfAlbum['description'] !== '') {
                        $add('Album description (Last.fm wiki): ' . mb_strimwidth($lfAlbum['description'], 0, self::DESC_CHARS, '…'), 'lastfm:album-wiki');
                    }
                }
            } elseif ($otherMbid) {
                $issues[] = 'Last.fm data skipped: Last.fm links this name to a different MusicBrainz artist';
            } else {
                $issues[] = 'Last.fm data skipped: cannot confirm it is the same artist (no MBID, album unknown)';
            }
        } elseif ($lastfmChecked) {
            $issues[] = 'Last.fm does not know this artist';
        }

        $genreTags = array_values(array_unique(array_filter(
            [...$releaseTags, ...$mbTags, ...$lfArtistTags, ...$lfAlbumTags],
            static fn ($t) => TagClassifier::classify((string) $t) === 'genre',
        )));
        $hasText = (bool) array_filter($facts, static fn ($f) => str_contains($f['source'], 'wiki'));
        $sources = count(array_unique(array_map(static fn ($f) => explode(':', $f['source'])[0], $facts)));
        $quality = match (true) {
            $genreTags === [] => 'insufficient',
            // Spelling variants ("indie-rock", "indie rock") are one genre.
            count(array_unique(array_map(TagClassifier::canonical(...), $genreTags))) >= 2 && ($hasText || $sources >= 2) => 'good',
            default => 'partial',
        };
        if ($quality === 'insufficient') {
            $issues[] = 'no genre information anywhere - cannot be judged musically';
        }

        // Documented musical properties ("aggressive", "harsh vocals"), kept apart
        // from genres with where they come from - an artist's properties are not
        // the album's. Not used in the score. No track-level tags are collected.
        $properties = [];
        $collect = static function (array $tags, string $source, string $level) use (&$properties, $primary): void {
            foreach ($tags as $t) {
                [$name, $weight] = is_array($t) ? [(string) $t['name'], $t['count'] ?? null] : [(string) $t, null];
                if (TagClassifier::classify($name, $primary) === 'property') {
                    $properties[] = ['tag' => $name, 'name' => TagClassifier::canonical($name), 'dimension' => TagClassifier::property($name),
                        'source' => $source, 'level' => $level, 'weight' => $weight];
                }
            }
        };
        $collect($releaseTags, 'musicbrainz:release-group', 'album');
        $collect($tagRows ?? [], 'musicbrainz:artist', 'artist');
        if ($lastfmUsed) {
            $collect($lfArtist['tags'], 'lastfm:artist', 'artist');
            $collect($lfAlbum['tags'] ?? [], 'lastfm:album', 'album');
        }

        return [
            'facts' => $facts,
            'genre_tags' => array_slice($genreTags, 0, 15),
            'properties' => $properties,
            'families' => TagClassifier::familiesOf($genreTags),
            'country' => $mbArtist['country'] ?? null,
            'lastfm_used' => $lastfmUsed,
            'metadata_quality' => $quality,
            'issues' => $issues,
        ];
    }
}
