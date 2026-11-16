<?php

declare(strict_types=1);

/**
 * CLI: fetches a snapshot of a user's Last.fm listening profile as JSON -
 * top artists for several periods plus top albums. Read-only; prints to
 * stdout and stores nothing.
 *
 *   docker compose exec php php /var/www/api/bin/lastfm-taste-snapshot.php > snapshot.json
 *
 * Not reachable over HTTP (api/bin/ is denied). Never prints the API key.
 */

use MusicRadar\LastFm\LastFmClient;
use MusicRadar\LastFm\LastFmException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$cfg = $app->config['lastfm'];
$user = (string) $cfg['username'];

try {
    $client = new LastFmClient((string) $cfg['api_key']);
    $snapshot = [
        'user' => $user,
        'fetched_at' => date(DATE_ATOM),
        'artists' => [
            'overall' => $client->userTopArtists($user, 'overall', 30),
            '12month' => $client->userTopArtists($user, '12month', 30),
            '3month' => $client->userTopArtists($user, '3month', 30),
        ],
        'albums' => [
            '12month' => $client->userTopAlbums($user, '12month', 20),
        ],
    ];
} catch (LastFmException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
