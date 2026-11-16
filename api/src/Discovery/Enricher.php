<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

use MusicRadar\LastFm\LastFmClient;
use MusicRadar\LastFm\LastFmException;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\MusicBrainz\MusicBrainzException;

/**
 * Fetches data for one release (MusicBrainz artist lookup, Last.fm artist
 * info/tags and album info) and turns it into documented facts via
 * FactBuilder. Used by the one-off experiment; the incremental pipeline
 * fetches in batches and calls FactBuilder directly.
 */
final class Enricher
{
    public function __construct(
        private readonly MusicBrainzClient $mb,
        private readonly LastFmClient $lastfm,
    ) {
    }

    public function enrich(array $c): array
    {
        $issues = [];
        $mbArtist = null;
        try {
            $mbArtist = $this->mb->lookupArtist($c['artist']['mbid']);
        } catch (MusicBrainzException $e) {
            $issues[] = 'MusicBrainz artist lookup failed: ' . $e->getMessage();
        }

        $lfArtist = null;
        $lfAlbum = null;
        $checked = true;
        try {
            $name = $c['artist']['primary_name'];
            $info = $this->lastfm->artistInfo($name);
            if ($info !== null) {
                $lfAlbum = $this->lastfm->albumInfo($name, $c['title']);
                // Weighted tags are better than the 5 from getInfo.
                $info['tags'] = array_column($this->lastfm->artistTopTags($name)['tags'], 'name');
                $lfArtist = $info;
            }
        } catch (LastFmException $e) {
            $issues[] = 'Last.fm lookup failed: ' . $e->getMessage();
            $checked = false;
        }

        $built = FactBuilder::build($c, $mbArtist, $lfArtist, $lfAlbum, $checked);
        $built['issues'] = [...$issues, ...$built['issues']];
        return $c + $built;
    }
}
