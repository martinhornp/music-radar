<?php

declare(strict_types=1);

/**
 * CLI check of the Last.fm setup: prints a user's top artists.
 *
 *   docker compose exec php php /var/www/api/bin/lastfm-top-artists.php [limit] [period]
 *
 * Not reachable over HTTP (api/.htaccess only allows index.php, and bin/ is
 * denied). Never prints the API key.
 */

use MusicRadar\LastFm\LastFmClient;
use MusicRadar\LastFm\LastFmException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$cfg = $app->config['lastfm'];

$limit = (int) ($argv[1] ?? 5);
$period = $argv[2] ?? 'overall';

try {
    $client = new LastFmClient((string) $cfg['api_key']);
    $artists = $client->userTopArtists((string) $cfg['username'], $period, $limit);
} catch (LastFmException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

printf("Top %d artists for %s (period: %s)\n\n", count($artists), $cfg['username'], $period);
printf("  %-3s %-34s %10s\n", '#', 'Artist', 'Plays');
printf("  %s\n", str_repeat('-', 49));
foreach ($artists as $i => $a) {
    printf("  %-3d %-34s %10s\n", $i + 1, mb_strimwidth($a['name'], 0, 34, '…'), number_format($a['playcount'], 0, ',', '.'));
}
