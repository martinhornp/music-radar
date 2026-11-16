<?php

declare(strict_types=1);

/**
 * CLI: how the stored tags of the releases in the window are classified
 * (genre / property / meta / noise / unknown), per source and level -
 * read only, no API calls, so it can be repeated at any time.
 *
 *   docker compose exec php php /var/www/api/bin/tag-report.php [--days=30] [--top=25] [--json]
 *
 * Unknown tags are listed by how often they occur: the candidates to add to
 * api/data/tag-classes.php (as genre, property or noise pattern).
 */

use MusicRadar\Database\Database;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Taste\TagClassifier;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$opt = static function (string $name, string $default) use ($argv): string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
};
$days = (int) $opt('days', '30');
$top = (int) $opt('top', '25');
$store = new DiscoveryStore(Database::connect($app->config['db']));
$to = date('Y-m-d');
$rows = $store->releasesInWindow(date('Y-m-d', strtotime("$to -$days days")), $to);
$artists = $store->artistsByMbid(array_column($rows, 'artist_mbid'));

// Every stored tag occurrence: [tag, source, level].
$occurrences = [];
foreach ($rows as $r) {
    foreach (json_decode((string) $r['release_tags'], true) ?: [] as $t) {
        $occurrences[] = [(string) $t, 'musicbrainz:release-group', 'album', $r['primary_name']];
    }
    if ($r['lastfm_album_status'] === 'found') {
        foreach (json_decode((string) $r['lastfm_album'], true)['tags'] ?? [] as $t) {
            $occurrences[] = [(string) $t, 'lastfm:album', 'album', $r['primary_name']];
        }
    }
}
foreach ($artists as $a) {
    $mb = json_decode((string) $a['mb'], true) ?: [];
    foreach ([...($mb['genres'] ?? []), ...($mb['tags'] ?? [])] as $t) {
        $occurrences[] = [(string) $t['name'], 'musicbrainz:artist', 'artist', (string) $a['name']];
    }
    if ($a['lastfm_status'] === 'found') {
        foreach (json_decode((string) $a['lastfm'], true)['tags'] ?? [] as $t) {
            $occurrences[] = [(string) $t, 'lastfm:artist', 'artist', (string) $a['name']];
        }
    }
}

$byClass = [];
$bySource = [];
$tags = [];
$properties = [];
foreach ($occurrences as [$tag, $source, $level, $artist]) {
    $class = TagClassifier::classify($tag, $artist);
    $byClass[$class] = ($byClass[$class] ?? 0) + 1;
    $bySource[$source][$class] = ($bySource[$source][$class] ?? 0) + 1;
    $name = TagClassifier::canonical($tag);
    $tags[$class][$name] = ($tags[$class][$name] ?? 0) + 1;
    if ($class === 'property') {
        $dim = (string) TagClassifier::property($tag);
        $properties[$level][$dim] = ($properties[$level][$dim] ?? 0) + 1;
    }
}
foreach ($tags as &$list) {
    arsort($list);
}
unset($list);
ksort($byClass);
$report = [
    'window' => "last $days days",
    'releases' => count($rows),
    'artists' => count($artists),
    'tag_occurrences' => count($occurrences),
    'by_class' => $byClass,
    'by_source' => $bySource,
    'distinct' => array_map('count', $tags),
    'properties_by_level' => $properties,
    'top' => array_map(static fn ($l) => array_slice($l, 0, $top, true), $tags),
];
if (in_array('--json', $argv, true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}
printf("Tags of %d releases and %d artists (%s): %d occurrences\n", $report['releases'], $report['artists'], $report['window'], $report['tag_occurrences']);
foreach ($byClass as $class => $n) {
    printf("  %-9s %6d occurrences, %5d distinct (%.0f%%)\n", $class, $n, $report['distinct'][$class], 100 * $n / max(1, count($occurrences)));
}
echo "\nBy source:\n";
foreach ($bySource as $source => $c) {
    ksort($c);
    echo "  $source: " . json_encode($c) . "\n";
}
echo "\nProperties by level and dimension: " . json_encode($properties) . "\n";
foreach (['noise', 'unknown', 'property', 'meta', 'genre'] as $class) {
    echo "\nMost common $class:\n  " . implode(', ', array_map(static fn ($t, $n) => "$t ($n)", array_keys($report['top'][$class] ?? []), $report['top'][$class] ?? [])) . "\n";
}
