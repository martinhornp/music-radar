<?php

declare(strict_types=1);

/**
 * Incremental discovery over the whole pool of new albums/EPs (no sampling).
 * Safe to run repeatedly: only new or due work is done (see DiscoveryPipeline).
 * Free: MusicBrainz + Last.fm API, state in a local SQLite store.
 *
 *   docker compose exec php php /var/www/api/bin/discovery-run.php [options]
 *
 *   --budget=SECONDS   stop starting new work after N seconds (0 = no limit);
 *                      on shared hosting e.g. 25 per invocation
 *   --profile=FILE     music profile JSON (music-profile.php --json); default
 *                      api/var/profile.json
 *   --compare=FILE[,FILE]  compare with earlier result.json files
 *   --store=FILE       SQLite file (default api/var/discovery.sqlite)
 *   --feedback=SOURCE  your ratings / "Not interested": a URL of GET
 *                      /api/feedback, "db" or "none" (default: feedback_source
 *                      in config/discovery.php - the live site)
 *
 * Writes result.json + report.txt to api/var/experiments/<run>-pipeline/.
 */

use MusicRadar\Discovery\DiscoveryPipeline;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Discovery\RunComparison;
use MusicRadar\Feedback\FeedbackSet;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\Recommend\ArtistInterest;
use MusicRadar\Recommend\RecommendationEngine;
use MusicRadar\Recommend\TasteModel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$cfg = $app->config;
$recCfg = require __DIR__ . '/../config/recommender.php';
$discCfg = require __DIR__ . '/../config/discovery.php';
$var = __DIR__ . '/../var';

$opt = static function (string $name, ?string $default = null) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
};
$log = static fn (string $m) => fwrite(STDERR, '[' . date('H:i:s') . "] $m\n");

// One run at a time.
$lock = fopen("$var/discovery.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another discovery run is in progress - exiting.\n");
    exit(3);
}

$profile = json_decode((string) file_get_contents($opt('profile', "$var/profile.json")), true);
if (!is_array($profile)) {
    fwrite(STDERR, "No music profile - create one with music-profile.php --json > api/var/profile.json\n");
    exit(1);
}

// Your feedback, from where it is stored (never from a local copy).
$feedbackSource = (string) $opt('feedback', $discCfg['feedback_source']);
try {
    $feedbackSet = FeedbackSet::fromSource($feedbackSource, $cfg['db']);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "
Stopping so the feed is not built without your ratings. Use --feedback=none to run without them.
");
    exit(1);
}
$feedback = $feedbackSet->forEngine();
$log(sprintf('feedback from %s: %s', $feedbackSource, json_encode($feedbackSet->summary())));

$store = DiscoveryStore::sqlite($opt('store', "$var/discovery.sqlite"));
$pipeline = new DiscoveryPipeline(
    $store,
    new MusicBrainzClient(sys_get_temp_dir() . '/music-radar/musicbrainz'),
    new LastFmClient((string) $cfg['lastfm']['api_key']),
    new RecommendationEngine(TasteModel::fromProfile($profile, $recCfg), $recCfg, $feedback),
    $profile,
    $discCfg,
    $log,
    new ArtistInterest($profile, $store->listening(), $recCfg['interest'] ?? [], $feedback['ratings'] ?? []),
);
$run = $pipeline->run(date('Y-m-d'), (int) $opt('budget', '0'));
$s = $run['stats'];
$all = $run['releases'];

// --- result.json (same core shape as the earlier experiments, for comparison)
$fam = [];
foreach ($run['assessments'] as $id => $a) {
    if (($all[$id]['source'] ?? '') === 'pool') {
        $f = $a['primary_family'] ?? null;
        $fam[$f ?? 'none'] = ($fam[$f ?? 'none'] ?? 0) + 1;
    }
}
arsort($fam);
$fin = $s['stages']['final'];
$out = [
    'experiment' => 'pipeline-v1 (whole pool)',
    'generated_at' => date(DATE_ATOM),
    'user' => $profile['user'],
    'settings' => ['window' => $s['window'], 'engine_config' => $recCfg, 'discovery_config' => $discCfg],
    // Exactly what the engine got, so export-feed.php can reproduce the scores.
    'feedback_used' => ['source' => $feedbackSource, 'summary' => $feedbackSet->summary()] + $feedback,
    'run' => $s,
    'counts' => [
        'examined' => $fin['releases_in_window'],
        'known_artist_releases' => count(array_filter($all, static fn ($r) => str_starts_with($r['source'], 'known_artist') || isset($r['watch_level']))),
        'metadata_insufficient' => $fin['metadata_final']['insufficient'],
        'decisions' => $fin['decisions'],
    ],
    'bias' => [
        'candidate_sources' => array_count_values(array_column($all, 'source')),
        'candidate_primary_families' => $fam,
        'candidate_countries' => (static function ($rows) { $c = array_count_values(array_filter(array_column($rows, 'country'))); arsort($c); return array_slice($c, 0, 15); })(array_values($all)),
    ],
    'recommended' => $run['recommended'],
    'wildcards' => $run['wildcards'],
    'duplicates' => $run['duplicates'],
    'assessments' => array_map(static fn ($a) => array_intersect_key($a, array_flip(['decision', 'fit', 'confidence', 'primary_family', 'date_status', 'note', 'not_selected'])), $run['assessments']),
    // Facts are kept only for picks and date checks (the full pool would be huge).
    'releases' => array_values(array_map(static function ($r) use ($run) {
        $keep = in_array($run['assessments'][$r['id']]['decision'] ?? '', ['recommended', 'wildcard', 'needs_date_check'], true);
        return $keep ? $r : array_diff_key($r, array_flip(['facts']));
    }, $all)),
];
if ($cmp = $opt('compare')) {
    foreach (explode(',', $cmp) as $file) {
        $out['comparisons'][] = RunComparison::compare(json_decode((string) file_get_contents($file), true), $out + ['assessments' => $run['assessments']]);
    }
}

$dir = "$var/experiments/" . date('Ymd-His') . '-pipeline';
mkdir($dir, 0775, true);
@file_put_contents("$var/.htaccess", "Require all denied\n");
file_put_contents("$dir/result.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// --- report ---------------------------------------------------------------------------
ob_start();
$p = static fn (string $x = '') => print($x . "\n");
$factText = static function (array $r, array $ids): array {
    $byId = array_column($r['facts'] ?? [], null, 'id');
    return array_map(static fn ($id) => "[$id] " . mb_strimwidth($byId[$id]['text'] ?? '?', 0, 150, '…') . ' (' . ($byId[$id]['source'] ?? '?') . ')', $ids);
};
$p("MARTINS MUSIC RADAR - discovery over the whole pool - {$out['generated_at']}");
$p(str_repeat('=', 100));
$p("Window: {$s['window'][0]} .. {$s['window'][1]}   Engine: rule-based PHP (no paid services)   Run " . ($s['complete'] ? 'COMPLETE' : 'PARTIAL - run again to continue'));
$p();
$p('PROCESSING (stage: seconds, work done)');
foreach ($s['stages'] as $stage => $st) {
    $p(sprintf('  %-9s %7ss  %s', $stage, $st['seconds'] ?? '-', json_encode(array_diff_key($st, array_flip(['seconds', 'complete'])), JSON_UNESCAPED_SLASHES)));
}
$p(sprintf('  total     %7ss', $s['seconds']));
$p();
$p('WHAT WAS EXAMINED');
$p(sprintf('  New albums/EPs examined (exact day + known artists): %d   (by origin: %s)', $fin['releases_in_window'], json_encode($fin['by_origin'])));
$p(sprintf('  Duplicates removed (same artist + title): %d', $fin['duplicates_removed']));
$m1 = $fin['metadata_after_musicbrainz'];
$m2 = $fin['metadata_final'];
$p(sprintf('  Metadata after MusicBrainz only:   good %d, partial %d, no genre data %d', $m1['good'], $m1['partial'], $m1['insufficient']));
$p(sprintf('  Metadata after Last.fm (selected): good %d, partial %d, no genre data %d', $m2['good'], $m2['partial'], $m2['insufficient']));
$lf = $s['stages']['lastfm'] ?? [];
if (isset($lf['selected'])) {
    $p(sprintf('  Needed further investigation (Last.fm): %d  %s', $lf['selected'], json_encode($lf['reasons'])));
}
$p('  Decisions: ' . json_encode($fin['decisions']));
$p(sprintf('  Feed: Recommended %d (by origin %s; limited data %d), Wildcards %d',
    count($run['recommended']), json_encode($fin['recommended_by_origin']), $fin['recommended_limited_data'], $fin['wildcards']));

$known = array_filter($all, static fn ($r) => isset($r['watch_level']));
$p();
$p('NEW RELEASES FROM ARTISTS YOU KNOW (registered independently of the score)');
$p(str_repeat('-', 100));
if (!$known) {
    $p('  none');
}
foreach ($known as $id => $r) {
    $p(sprintf('  %-10s  %-26s %-36s [%s]  %s', $r['first_release_date'], mb_strimwidth($r['artist']['name'], 0, 26, '…'), mb_strimwidth($r['title'], 0, 36, '…'), $r['watch_level'], $run['assessments'][$id]['decision']));
}

$show = static function (array $picks, string $title) use ($p, $all, $factText): void {
    $p();
    $p($title);
    $p(str_repeat('-', 100));
    if (!$picks) {
        $p('  none');
    }
    foreach ($picks as $n => $pick) {
        $r = $all[$pick['id']];
        $tags = array_filter([$pick['origin'] !== 'discovery' ? $pick['origin'] : null, $pick['data_quality'] === 'limited' ? 'limited data' : null, $pick['angle']]);
        $p(sprintf('%2d. %s - %s  (%s, %s)%s', $n + 1, $r['artist']['name'], $r['title'], $r['type'], $r['first_release_date'], $tags ? '  [' . implode(', ', $tags) . ']' : ''));
        $p('    Why: ' . $pick['why']);
        foreach ($pick['reasons'] as $reason) {
            $p('     - ' . $reason['text']);
            foreach ($factText($r, array_slice($reason['facts'], 0, 2)) as $f) {
                $p('         ' . $f);
            }
        }
        $sg = $pick['signals'] + ['genre' => 0, 'family' => 0, 'neighbours' => 0, 'blend' => 0, 'novelty' => 0, 'outside' => 0];
        $p(sprintf('    Signals: genre %.2f, family %.2f, neighbours %.2f, blend %.2f, novelty %.2f, outside %.2f | fit %.2f, confidence %.2f',
            $sg['genre'], $sg['family'], $sg['neighbours'], $sg['blend'], $sg['novelty'], $sg['outside'], $pick['fit'], $pick['confidence']));
        $p('    ' . $r['musicbrainz_url']);
    }
};
$show($run['recommended'], 'RECOMMENDED FOR YOU (no limit; known artists always included, then ordered by fit with variation)');
$show($run['wildcards'], 'WILDCARDS');

$check = array_filter($run['assessments'], static fn ($a) => $a['decision'] === 'needs_date_check');
$p();
$p('NEEDS DATE CHECK (not shown as new until the release day is confirmed)');
$p(str_repeat('-', 100));
if (!$check) {
    $p('  none');
}
foreach ($check as $id => $a) {
    $p(sprintf('  %-10s %s - %s  [%s]  %s', $all[$id]['first_release_date'], $all[$id]['artist']['name'], $all[$id]['title'], $all[$id]['date_check']['status'], $all[$id]['date_check']['note']));
}

$near = array_filter($run['assessments'], static fn ($a) => $a['decision'] === 'not_selected');
uasort($near, static fn ($a, $b) => $b['fit'] <=> $a['fit']);
$p();
$p('NEAR MISSES');
$p(str_repeat('-', 100));
foreach (array_slice($near, 0, 10, true) as $id => $a) {
    $p(sprintf('  fit %.2f conf %.2f  %s - %s  [%s]%s', $a['fit'], $a['confidence'], $all[$id]['artist']['name'], $all[$id]['title'], $a['primary_family'] ?? '-', isset($a['not_selected']) ? "  ({$a['not_selected']})" : ''));
}

$p();
$p('BIAS CHECK (whole pool)');
$p(str_repeat('-', 100));
$p('  Primary genre family of pool releases: ' . json_encode(array_slice($fam, 0, 14)));
$p('  Artist countries: ' . json_encode($out['bias']['candidate_countries']));

foreach ($out['comparisons'] ?? [] as $cm) {
    $p();
    $p("COMPARED WITH {$cm['previous_run']}");
    $p(str_repeat('-', 100));
    foreach (['previous', 'now'] as $k) {
        $x = $cm[$k];
        $p(sprintf('  %-8s candidates %5d | known-artist %s | no genre data %s | families %d (largest %s %d%%) | recommended %d, wildcards %d',
            $k, $x['candidates'], $x['known_artist_releases'] ?? '?', $x['no_genre_data'] ?? '?', $x['genres']['families'], $x['genres']['top_family'], $x['genres']['top_share'] * 100, $x['recommended'], $x['wildcards']));
    }
    foreach (['recommended' => 'Recommended', 'wildcards' => 'Wildcards'] as $key => $title) {
        $d = $cm[$key];
        $p("  $title: kept " . count($d['kept']) . ', dropped ' . count($d['dropped']) . ', added ' . count($d['added']));
        foreach ($d['kept'] as $k) {
            $p("    = $k");
        }
        foreach ($d['dropped'] as $x) {
            $p("    - {$x['release']}: {$x['why']}");
        }
    }
}

$failures = $store->failures();
$p();
$p('FAILURES (retried on the next run): ' . count($failures));
foreach (array_slice($failures, 0, 10) as $f) {
    $p("  {$f['stage']} {$f['item']} x{$f['attempts']}: " . mb_strimwidth($f['message'], 0, 120, '…'));
}
$p();
$p("Saved: $dir/result.json");
$report = (string) ob_get_clean();
file_put_contents("$dir/report.txt", $report);
echo $report;
