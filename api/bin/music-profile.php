<?php

declare(strict_types=1);

/**
 * CLI: builds the music profile from Last.fm + MusicBrainz and shows the
 * artists, their MusicBrainz IDs, genre data, match status, watch level
 * (following / interested) and problems.
 *
 *   docker compose exec php php /var/www/api/bin/music-profile.php                 # report
 *   docker compose exec php php /var/www/api/bin/music-profile.php --json          # full profile (JSON)
 *   ... --previous=/path/profile.json   keep artists from an earlier profile that
 *                                       dropped out of the Last.fm top lists
 *
 * Read-only: nothing is stored except a MusicBrainz response cache in the
 * system temp dir (outside the web root and the deploy package). Progress goes
 * to stderr. Not reachable over HTTP; never prints the API key.
 */

use MusicRadar\LastFm\LastFmClient;
use MusicRadar\LastFm\LastFmException;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\Taste\ArtistMatch;
use MusicRadar\Taste\FollowPolicy;
use MusicRadar\Taste\ProfileBuilder;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$cfg = $app->config['lastfm'];
$asJson = in_array('--json', $argv, true);
$overrides = require __DIR__ . '/../data/artist-overrides.php';

$previous = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--previous=')) {
        $previous = json_decode((string) @file_get_contents(substr($arg, 11)), true);
        if (!is_array($previous)) {
            fwrite(STDERR, "Error: could not read previous profile from $arg\n");
            exit(1);
        }
    }
}

try {
    $builder = new ProfileBuilder(
        new LastFmClient((string) $cfg['api_key']),
        new MusicBrainzClient(sys_get_temp_dir() . '/music-radar/musicbrainz'),
        $overrides,
        static fn (string $msg) => fwrite(STDERR, $msg . PHP_EOL),
        $previous,
    );
    $profile = $builder->build((string) $cfg['username']);
} catch (LastFmException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($asJson) {
    echo json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit;
}

// ---------------------------------------------------------------- report
$artists = $profile['artists'];
$line = static fn (string $s = '') => print($s . PHP_EOL);
$cut = static fn (?string $s, int $n) => $s === null ? '-' : mb_strimwidth($s, 0, $n, '…');
$byLevel = static fn (string $level) => array_values(array_filter($artists, static fn ($a) => $a['watch']['level'] === $level));
$isResolved = static fn ($a) => in_array($a['musicbrainz']['status'], [ArtistMatch::CONFIRMED, ArtistMatch::MANUAL, ArtistMatch::MANUAL_NONE], true);

$line("MUSIC PROFILE - {$profile['user']} ({$profile['generated_at']})");
$line(str_repeat('=', 110));
$line('MusicBrainz: ' . json_encode($profile['sources']['musicbrainz']) . '   Watch: ' . json_encode($profile['watch_summary'])
    . '   Detectable now: ' . count(array_filter($artists, static fn ($a) => $a['monitoring']['enabled'])) . '/' . count($artists));

$table = static function (array $rows, string $title, string $explain) use ($line, $cut): void {
    $line();
    $line(sprintf('%s (%d) - %s', $title, count($rows), $explain));
    $line(str_repeat('-', 150));
    $line(sprintf('%-28s %-9s %-12s %-3s %-36s %-44s %s', 'ARTIST', 'LISTEN', 'MATCH', 'CC', 'MUSICBRAINZ ID', 'WHY', 'GENRES (source)'));
    foreach ($rows as $a) {
        $m = $a['musicbrainz'];
        $line(sprintf(
            '%-28s %-9s %-12s %-3s %-36s %-44s %s%s',
            $cut($a['name'], 28),
            $a['lastfm']['retained'] ? 'retained' : $a['lastfm']['listening'],
            $m['status'] . (in_array($m['status'], ['confirmed'], true) ? '/' . $m['confidence'][0] : ''),
            $a['country'] ?? '-',
            $m['mbid'] ?? '-',
            $cut($a['watch']['reason'], 44),
            $cut(implode(', ', array_keys($a['genres']['weights'])), 34) . " ({$a['genres']['source']})",
            $a['monitoring']['enabled'] ? '' : '  [NOT DETECTABLE]',
        ));
    }
};

$order = ['core' => 0, 'current' => 1, 'recent' => 2, 'dormant' => 3];
$sort = static fn (array $rows) => (static function () use ($rows, $order) {
    usort($rows, static fn ($a, $b) => [$order[$a['lastfm']['listening']], $a['name']] <=> [$order[$b['lastfm']['listening']], $b['name']]);
    return $rows;
})();

$table($sort($byLevel(FollowPolicy::FOLLOWING)), 'FOLLOWING', 'you follow these - new albums/EPs always included');
$interested = $sort($byLevel(FollowPolicy::INTERESTED));
$table($interested, 'INTERESTED', 'from your Last.fm history - new albums/EPs detected and evaluated');
$ignored = $byLevel(FollowPolicy::IGNORED);
if ($ignored) {
    $table($ignored, 'IGNORED', 'not watched');
}

$suggest = array_filter($interested, static fn ($a) => $a['watch']['suggest_following'] !== null);
$line();
$line(sprintf('SUGGESTED TO FOLLOW (%d) - strong, lasting signals; add \'level\' => \'following\' to approve', count($suggest)));
$line(str_repeat('-', 110));
$line('  ' . implode(', ', array_map(static fn ($a) => "{$a['name']} ({$a['watch']['suggest_following']})", $suggest)));

$line();
$unresolved = array_filter($artists, static fn ($a) => !$isResolved($a));
$line(sprintf('UNRESOLVED MUSICBRAINZ MATCHES (%d)', count($unresolved)));
$line(str_repeat('-', 110));
if ($unresolved === []) {
    $line('  none');
}
foreach ($unresolved as $a) {
    $m = $a['musicbrainz'];
    $line("* {$a['name']} [{$m['status']}]");
    foreach ($m['problems'] as $p) {
        $line("    - $p");
    }
    foreach ($m['candidates'] as $c) {
        $line(sprintf('    ? %s  %s%s%s', $c['mbid'], $c['name'], isset($c['country']) ? " ({$c['country']})" : '', isset($c['disambiguation']) ? " - {$c['disambiguation']}" : ''));
    }
}

$line();
$line('OTHER DATA ISSUES');
$line(str_repeat('-', 110));
foreach ($artists as $a) {
    $other = array_filter($a['issues'], static fn ($i) => !str_contains($i, 'MusicBrainz match needs your review') && !str_contains($i, "can't be detected"));
    if ($isResolved($a) && $other) {
        $line("* {$a['name']}: " . implode('; ', $other));
    }
}

$line();
$line('GENRE FAMILIES: ' . implode(' | ', array_map(static fn ($f) => sprintf('%s %.1f%%', $f['name'], $f['weight'] * 100), array_slice($profile['families'], 0, 10))));
