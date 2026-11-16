<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\MusicBrainz\MusicBrainzException;

/**
 * New albums/EPs from artists the listener already knows (the music profile),
 * found directly per artist - independent of any AI judgement. A release from
 * a "following" artist is always included in the recommendations; releases
 * from "interested" artists are always listed and evaluated.
 */
final class KnownArtistReleases
{
    public function __construct(private readonly MusicBrainzClient $mb, private $log = null)
    {
    }

    /**
     * @param list<array<string, mixed>> $profileArtists  'artists' from the music profile
     * @return array{releases: list<array<string, mixed>>, checked: int, skipped: list<string>, errors: list<string>}
     */
    public function find(array $profileArtists, string $from, string $to): array
    {
        $releases = [];
        $skipped = [];
        $errors = [];
        $checked = 0;
        foreach ($profileArtists as $a) {
            if (!self::watched($a)) {
                if (($a['watch']['level'] ?? null) !== 'ignored') {
                    $skipped[] = $a['name'];
                }
                continue;
            }
            $checked++;
            if ($this->log) {
                ($this->log)("Known artist $checked: {$a['name']}");
            }
            try {
                $releases = [...$releases, ...$this->forArtist($a, $from, $to)];
            } catch (MusicBrainzException $e) {
                $errors[] = "{$a['name']}: {$e->getMessage()}";
            }
        }
        return ['releases' => $releases, 'checked' => $checked, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** An artist from the profile that is monitored (has an MBID, not ignored). */
    public static function watched(array $a): bool
    {
        return ($a['musicbrainz']['mbid'] ?? null) !== null && ($a['watch']['level'] ?? 'interested') !== 'ignored';
    }

    /**
     * New releases of one known artist in the window (one MusicBrainz request
     * per 100 release groups) - also collaborations where the artist is
     * credited second or later. Without a credit in the answer (should not
     * happen) the artist is used as a stand-in, marked 'credits' => null so
     * it never replaces a stored full credit.
     *
     * @throws MusicBrainzException
     * @return list<array<string, mixed>>
     */
    public function forArtist(array $a, string $from, string $to): array
    {
        $mbid = $a['musicbrainz']['mbid'];
        $level = $a['watch']['level'] ?? 'interested';
        $releases = [];
        foreach ($this->mb->artistReleaseGroups($mbid) as $rg) {
            $date = (string) ($rg['first-release-date'] ?? '');
            if ($date === '' || !CandidateFinder::newMusicType($rg)) {
                continue;
            }
            // Partial dates (2026-10 / 2026) count if they can fall in the window.
            // 2026 -> 2026-01-01, 2026-10 -> 2026-10-01, full dates unchanged
            $start = substr($date . substr('-01-01', strlen($date) - 4), 0, 10);
            $end = strlen($date) === 4 ? "$date-12-31" : (strlen($date) === 7 ? date('Y-m-t', strtotime("$date-01")) : $date);
            if ($end < $from || $start > $to) {
                continue;
            }
            $releases[] = [
                'id' => (string) $rg['id'],
                'title' => (string) $rg['title'],
                'type' => (string) $rg['primary-type'],
                'first_release_date' => $date,
                'date_note' => match (strlen($date)) {
                    10 => null,
                    7 => 'only the month is known',
                    default => 'only the year is known - may be older than this window',
                },
                // The release's own credit (a collaboration keeps every artist, in
                // MusicBrainz' order) - never replaced by the profile artist.
                'artist' => ($rg['artist-credit'] ?? []) !== []
                    ? CandidateFinder::artist($rg['artist-credit'])
                    : ['name' => $a['name'], 'mbid' => $mbid, 'primary_name' => $a['name'], 'credited_artists' => 1, 'credits' => null],
                'watch_level' => $level,
                'release_tags' => [],
                'source' => "known_artist:$level",
                'musicbrainz_url' => "https://musicbrainz.org/release-group/{$rg['id']}",
            ];
        }
        return $releases;
    }
}
