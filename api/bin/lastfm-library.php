<?php

declare(strict_types=1);

/**
 * CLI: fetches the listener's whole Last.fm artist library (every artist with
 * plays all time / last 12 months / last 3 months) for the artist-interest
 * signal. Run by the weekly profile refresh, which uploads the result.
 *
 *   php api/bin/lastfm-library.php --profile=profile.json > library.json
 *   php api/bin/lastfm-library.php --profile=profile.json --save   # into the configured database (local)
 *
 * The profile links names to the MusicBrainz ids confirmed there; no
 * MusicBrainz lookups are made. ~17 Last.fm requests. Progress to stderr;
 * never prints the API key. Not reachable over HTTP.
 */

use MusicRadar\Database\Database;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\LastFm\LastFmException;
use MusicRadar\Taste\ListeningLibrary;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$profile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--profile=')) {
        $profile = json_decode((string) @file_get_contents(substr($arg, 10)), true);
        if (!is_array($profile)) {
            fwrite(STDERR, "Error: could not read the profile from $arg\n");
            exit(1);
        }
    }
}

try {
    $artists = (new ListeningLibrary(new LastFmClient((string) $app->config['lastfm']['api_key'])))
        ->build((string) $app->config['lastfm']['username'], $profile);
} catch (LastFmException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
$ids = array_count_values(array_column($artists, 'identity'));
fwrite(STDERR, sprintf("Last.fm library: %d artists (%s)\n", count($artists), json_encode($ids)));

if (in_array('--save', $argv, true)) {
    $store = new DiscoveryStore(Database::connect($app->config['db']));
    $store->replaceListening($artists, 'lastfm:user.getTopArtists', date(DATE_ATOM));
    fwrite(STDERR, "Saved to the database.\n");
    exit;
}
echo json_encode(['artists' => array_map(static fn ($a) => array_diff_key($a, ['name_key' => 1]), $artists)],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
