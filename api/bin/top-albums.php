<?php

declare(strict_types=1);

/**
 * CLI: your most played albums on Last.fm (all time) as MusicBrainz
 * albums/EPs, for the "Import top albums" workflow, which uploads the result
 * (POST /api/internal/top-albums adds the ones not stored yet).
 *
 *   php api/bin/top-albums.php [--limit=100] > top-albums.json
 *
 * Up to 3 x limit Last.fm albums are looked at (singles and unknown albums are
 * skipped); one or two MusicBrainz searches per album (1 per second). Progress
 * to stderr; never prints the API key. Not reachable over HTTP.
 */

use MusicRadar\LastFm\LastFmClient;
use MusicRadar\LastFm\LastFmException;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\TopAlbums\TopAlbums;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$log = static fn (string $m) => fwrite(STDERR, $m . PHP_EOL);
$limit = TopAlbums::LIMIT;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
        if ($limit < 1 || $limit > TopAlbums::MAX) {
            $log('Error: --limit must be 1-' . TopAlbums::MAX);
            exit(1);
        }
    }
}
try {
    $albums = TopAlbums::resolve(new LastFmClient((string) $app->config['lastfm']['api_key']), new MusicBrainzClient(),
        (string) $app->config['lastfm']['username'], $log, $limit);
} catch (LastFmException $e) {
    $log('Error: ' . $e->getMessage());
    exit(1);
}
$log(sprintf('Top albums: %d albums/EPs', count($albums)));
echo json_encode(['albums' => $albums], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
