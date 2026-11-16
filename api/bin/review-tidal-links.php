<?php

declare(strict_types=1);

/** Local, read-only source study. Writes only an isolated SQLite file and report under api/var/eval. */
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Links\TidalImport;
use MusicRadar\Links\TidalLinkStore;
use MusicRadar\MusicBrainz\MusicBrainzClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';
$options = getopt('', ['feed:', 'today:', 'out:']);
$root = realpath(__DIR__ . '/../var/eval');
$feedPath = realpath($options['feed'] ?? '');
$out = $options['out'] ?? '';
if ($root === false || $feedPath === false || !str_starts_with($feedPath, $root . DIRECTORY_SEPARATOR)
    || realpath(dirname($out)) !== $root || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $options['today'] ?? '')) {
    fwrite(STDERR, "Use --feed=<api/var/eval/snapshot.json> --today=YYYY-MM-DD --out=<api/var/eval/report.json>\n");
    exit(2);
}
$today = $options['today'];
$from = date('Y-m-d', strtotime("$today -6 days"));
$now = $today . 'T12:00:00+01:00';
$feed = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($feedPath)), true, flags: JSON_THROW_ON_ERROR);
$cohort = array_values(array_filter($feed['releases'], static fn ($r) => in_array($r['type'], ['Album', 'EP'], true)
    && in_array($r['pick']['kind'], ['recommended', 'wildcard'], true) && $r['releaseDate'] >= $from && $r['releaseDate'] <= $today));
$dbPath = $out . '.sqlite';
if (is_file($dbPath)) {
    fwrite(STDERR, "Choose a new output path; an existing study database is never overwritten.\n");
    exit(2);
}
$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$discovery = new DiscoveryStore($db);
$cache = $root . '/tidal-new-releases-cache';
$mb = new MusicBrainzClient($cache, 86400, 10, 2);
$creditErrors = [];
$groups = $mb->releaseGroupsByIds(array_column($cohort, 'id'), 86400);
foreach ($cohort as $i => $r) {
    $credits = [];
    try {
        // The live feed exposes only the first artist MBID. Obtain the complete
        // MusicBrainz credit instead of guessing collaborators from display names.
        $group = $groups[$r['id']] ?? $mb->lookupReleaseGroupCredits($r['id']);
        foreach ($group['artist-credit'] ?? [] as $c) {
            $credits[] = ['mbid' => $c['artist']['id'], 'name' => $c['name'] ?? $c['artist']['name'],
                'artist_name' => $c['artist']['name'], 'joinphrase' => $c['joinphrase'] ?? ''];
        }
        if ($credits === [] || !in_array($r['artistMbid'], array_column($credits, 'mbid'), true)) {
            throw new RuntimeException('Missing or conflicting source credits');
        }
    } catch (Throwable) {
        $creditErrors[] = $r['id'];
        $credits = [['mbid' => 'unverified-credit', 'name' => $r['artist'], 'artist_name' => $r['artist'], 'joinphrase' => '']];
    }
    $discovery->saveRelease(['id' => $r['id'], 'title' => $r['title'], 'type' => $r['type'], 'first_release_date' => $r['releaseDate'],
        'artist' => ['mbid' => $r['artistMbid'], 'name' => $r['artist'], 'primary_name' => $r['artist'],
            'credited_artists' => count($credits), 'credits' => $credits], 'release_tags' => $r['tags']], 'pool', null, $feed['generatedAt']);
    echo 'Credits ', $i + 1, '/', count($cohort), "\n";
}
$runs = new RecommendationStore($db);
$runs->install();
$runs->publish($feed, 'local-tidal-study', ['snapshot' => $feed['generatedAt']], $now);
$import = new TidalImport($db, new MusicBrainzClient($cache, 86400, 5, 1));
$store = new TidalLinkStore($db);
$started = time();
do {
    $current = (new DateTimeImmutable($now))->modify('+' . (time() - $started) . ' seconds')->format(DATE_ATOM);
    $result = $import->step($current, 18);
    echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
    if ($result['pending'] && $result['retry_after'] > 0) {
        sleep(min(60, $result['retry_after']));
    }
} while ($result['pending']);
$results = [];
$links = array_column($store->attach($cohort, $now), 'tidalAlbumId', 'id');
foreach ($cohort as $r) {
    $s = $db->prepare('SELECT status, album_id, edition_id, attempts FROM tidal_links WHERE release_id=?');
    $s->execute([$r['id']]);
    $check = $s->fetch(PDO::FETCH_ASSOC) ?: ['status' => 'pending'];
    $results[] = ['id' => $r['id'], 'artist' => $r['artist'], 'title' => $r['title'], 'date' => $r['releaseDate'],
        'pick' => $r['pick']['kind'], 'status' => $check['status'], 'tidalAlbumId' => $links[$r['id']] ?? null];
}
$report = ['snapshot_generated_at' => $feed['generatedAt'], 'from' => $from, 'to' => $today,
    'summary' => $store->summary(), 'credit_errors' => $creditErrors, 'results' => $results,
    'limitations' => ['No production database writes or scoring changes.', 'MusicBrainz metadata/relations only; no TIDAL catalog, playback or regional availability verification.']];
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo json_encode($report['summary']), "\n";
exit($report['summary']['pending'] || $report['summary']['error'] || $creditErrors ? 1 : 0);
