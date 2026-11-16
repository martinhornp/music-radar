<?php

declare(strict_types=1);

/**
 * Exports the picks of an existing discovery run to api/data/feed.json - the
 * file GET /api/recommendations serves on the website.
 *
 * Nothing is fetched and the selection is not recomputed: it reads a finished
 * run (result.json from discovery-run.php) and keeps only what the website
 * shows - no scores, listening counts, raw facts or other local data. Only the
 * reason texts are refreshed through the engine, so wording fixes reach the
 * site; it stops if any score would differ from the run.
 *
 *   php api/bin/export-feed.php [--from=api/var/experiments/<run>/result.json]
 *
 * Default: the newest *-pipeline run in api/var/experiments.
 */

use MusicRadar\Feed\FeedBuilder;
use MusicRadar\Recommend\RecommendationEngine;
use MusicRadar\Recommend\TasteModel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

$var = __DIR__ . '/../var';
$from = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--from=')) {
        $from = substr($a, 7);
    }
}
if ($from === null) {
    $runs = glob("$var/experiments/*-pipeline/result.json") ?: [];
    sort($runs);
    $from = end($runs) ?: null;
}
if ($from === null || !is_file($from)) {
    fwrite(STDERR, "No pipeline result found - run discovery-run.php first or pass --from=...\n");
    exit(1);
}

$run = json_decode((string) file_get_contents($from), true, flags: JSON_THROW_ON_ERROR);
$releases = array_column($run['releases'], null, 'id');
$profileFile = "$var/profile.json";
$profile = json_decode((string) file_get_contents($profileFile), true, flags: JSON_THROW_ON_ERROR);
$config = $run['settings']['engine_config'];
$feedback = array_intersect_key($run['feedback_used'] ?? [], ['ratings' => 1, 'not_interested_releases' => 1]);
$engine = new RecommendationEngine(TasteModel::fromProfile($profile, $config), $config, $feedback);

// Reason texts are refreshed through the engine (wording fixes reach the
// site); the selection itself is the run's, and any score change stops it.
$reasons = static function (array $pick, array $r) use ($engine): array {
    $now = $engine->explain($r, $pick['type'], $pick['origin']);
    if (abs($now['fit'] - $pick['fit']) > 1e-6) {
        fwrite(STDERR, "Score of {$r['id']} differs from the run ({$now['fit']} vs {$pick['fit']}) - profile or engine changed; re-run discovery-run.php first.
");
        exit(1);
    }
    return $now['reasons'];
};
$feed = FeedBuilder::feed($run['recommended'], $run['wildcards'], $releases, $run['assessments'], $profile,
    $run['generated_at'], $run['settings']['window'], (int) $run['counts']['examined'], $reasons);
$out = $feed['releases'];
$target = __DIR__ . '/../data/feed.json';
file_put_contents($target, json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

$kinds = array_count_values(array_column(array_column($out, 'pick'), 'kind'));
printf("Exported %d recommended + %d wildcards from %s\n-> %s (%d KB)\n",
    $kinds['recommended'] ?? 0, $kinds['wildcard'] ?? 0, $from, realpath($target), filesize($target) / 1024);
