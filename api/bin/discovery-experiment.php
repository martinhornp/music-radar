<?php

declare(strict_types=1);

/**
 * Local experiment: find real new albums/EPs on MusicBrainz, enrich them with
 * documented facts (MusicBrainz + Last.fm), check the listener's known
 * artists separately, and run our own rule-based recommendation engine.
 * Free to run - no paid services.
 *
 *   docker compose exec php php /var/www/api/bin/discovery-experiment.php [options]
 *
 *   --days=30            release window (first release date, up to today)
 *   --candidates=100     number of candidate releases to collect
 *   --random-share=1.0   share sampled uniformly from the whole pool (default:
 *                        all - genre-neutral). Below 1 the rest comes from
 *                        genre-tag searches based on your profile (biased).
 *   --compare=FILE       compare with an earlier result.json
 *   --seed=N             sampling seed (default: today) - same seed, same sample
 *   --profile=FILE       reuse a saved music profile (music-profile.php --json)
 *   --reuse=FILE         reuse releases from an earlier result.json (no data
 *                        collection - for tuning config/recommender.php)
 *   --data-only          collect and enrich only, no recommendations
 *
 * Writes result.json + report.txt to api/var/experiments/<run>/ (git-ignored,
 * not reachable over HTTP).
 */

use MusicRadar\Discovery\CandidateFinder;
use MusicRadar\Discovery\DateVerifier;
use MusicRadar\Discovery\Enricher;
use MusicRadar\Discovery\KnownArtistReleases;
use MusicRadar\Discovery\ProfileSummary;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\Recommend\RecommendationEngine;
use MusicRadar\Recommend\TasteModel;
use MusicRadar\Taste\ProfileBuilder;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$cfg = $app->config;
$recCfg = require __DIR__ . '/../config/recommender.php';

$opt = static function (string $name, ?string $default = null) use ($argv): ?string {
    foreach ($argv as $a) {
        if ($a === "--$name") {
            return '1';
        }
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
};
$log = static fn (string $m) => fwrite(STDERR, '[' . date('H:i:s') . "] $m\n");

$lastfm = new LastFmClient((string) $cfg['lastfm']['api_key']);
$mb = new MusicBrainzClient(sys_get_temp_dir() . '/music-radar/musicbrainz');

// --- 1. Music profile -------------------------------------------------------
if ($file = $opt('profile')) {
    $profile = json_decode((string) file_get_contents($file), true);
    $log("Profile loaded from $file");
} else {
    $log('Building music profile (Last.fm + MusicBrainz) ...');
    $profile = (new ProfileBuilder($lastfm, $mb, require __DIR__ . '/../data/artist-overrides.php', $log))
        ->build((string) $cfg['lastfm']['username']);
}

// --- 2-4. Releases: collect + enrich, or reuse --------------------------------
if ($reuse = $opt('reuse')) {
    $prev = json_decode((string) file_get_contents($reuse), true);
    $all = [];
    foreach ($prev['releases'] as $r) {
        $all[$r['id']] = $r;
    }
    $searchStats = $prev['candidate_search'];
    $knownStats = $prev['known_artists'];
    $knownReleases = array_values(array_filter($all, static fn ($r) => str_starts_with($r['source'], 'known_artist')));
    [$from, $to] = $prev['settings']['window'];
    $days = $prev['settings']['days'];
    $seed = $prev['settings']['seed'];
    $log(sprintf('Reusing %d releases from %s', count($all), $reuse));
} else {
    $days = (int) $opt('days', '30');
    $target = (int) $opt('candidates', '100');
    $seed = (int) $opt('seed', date('Ymd'));
    $to = date('Y-m-d');
    $from = date('Y-m-d', strtotime("-$days days"));

    $found = (new CandidateFinder($mb, $log))->find($from, $to, $target, (float) $opt('random-share', '1.0'), ProfileSummary::searchTags($profile), $seed);
    $searchStats = $found['stats'];
    $known = (new KnownArtistReleases($mb, $log))->find($profile['artists'], $from, $to);
    $knownStats = ['checked' => $known['checked'], 'skipped_no_mbid' => $known['skipped'], 'errors' => $known['errors']];
    $knownReleases = $known['releases'];

    $all = [];
    foreach ($found['candidates'] as $c) {
        $all[$c['id']] = $c;
    }
    $levelByMbid = [];
    foreach ($profile['artists'] as $a) {
        if ($a['musicbrainz']['mbid'] ?? null) {
            $levelByMbid[$a['musicbrainz']['mbid']] = $a['watch']['level'];
        }
    }
    foreach ($all as &$c) {
        if (isset($levelByMbid[$c['artist']['mbid']])) {
            $c['watch_level'] = $levelByMbid[$c['artist']['mbid']];
        }
    }
    unset($c);
    foreach ($knownReleases as $r) {
        $all[$r['id']] ??= $r;
        $all[$r['id']]['watch_level'] = $r['watch_level'];
    }

    $enricher = new Enricher($mb, $lastfm);
    $i = 0;
    foreach ($all as $id => $c) {
        $log(sprintf('Enrich %d/%d: %s - %s', ++$i, count($all), $c['artist']['name'], $c['title']));
        $all[$id] = $enricher->enrich($c);
    }
}

// --- 4b. Release dates: only a documented DAY counts as a certain new release ----
$dateVerifier = new DateVerifier($mb);
foreach ($all as $id => $r) {
    if (!isset($r['date_check'])) {
        if (strlen((string) $r['first_release_date']) < 10) {
            $log("Date check: {$r['artist']['name']} - {$r['title']} ({$r['first_release_date']})");
        }
        $all[$id]['date_check'] = $dateVerifier->verify($r, $from, $to);
    }
}

// Which releases are which (the numbers must add up).
$sampleIds = array_keys(array_filter($all, static fn ($r) => !str_starts_with($r['source'], 'known_artist')));
$knownIds = array_column($knownReleases, 'id');
$knownDuplicates = array_values(array_intersect($knownIds, $sampleIds));
$knownExtra = array_values(array_diff($knownIds, $sampleIds));

// --- 5. Recommendation engine (rule-based, free) -------------------------------
$result = null;
if ($opt('data-only') === null) {
    $engine = new RecommendationEngine(TasteModel::fromProfile($profile, $recCfg), $recCfg, feedback: []);
    $result = $engine->run($all);
}

// --- 6. Result -----------------------------------------------------------------
$q = array_count_values(array_column($all, 'metadata_quality'));
$discovered = array_filter($all, static fn ($r) => !str_starts_with($r['source'], 'known_artist'));
$srcOf = static fn (string $id) => str_starts_with($all[$id]['source'], 'tag:') ? 'tag' : $all[$id]['source'];
$count = static function (array $values): array {
    $c = array_count_values(array_filter($values, static fn ($v) => $v !== null && $v !== ''));
    arsort($c);
    return $c;
};
// Per group: how many, data quality, date status, and how many can really be judged.
$group = static function (array $ids) use ($all): array {
    $rows = array_map(static fn ($id) => $all[$id], $ids);
    $quality = array_count_values(array_column($rows, 'metadata_quality'));
    $dates = array_count_values(array_map(static fn ($r) => $r['date_check']['status'] ?? 'unconfirmed', $rows));
    return [
        'releases' => count($rows),
        'metadata' => ['good' => $quality['good'] ?? 0, 'partial' => $quality['partial'] ?? 0, 'insufficient' => $quality['insufficient'] ?? 0],
        'dates' => ['confirmed' => $dates['confirmed'] ?? 0, 'probable' => $dates['probable'] ?? 0, 'unconfirmed' => $dates['unconfirmed'] ?? 0],
        'missing_genre_data' => $quality['insufficient'] ?? 0,
        'assessable' => count(array_filter($rows, static fn ($r) => ($r['metadata_quality'] ?? '') !== 'insufficient' && ($r['date_check']['status'] ?? '') === 'confirmed')),
    ];
};

$out = [
    'experiment' => 'rule-engine-v2',
    'generated_at' => date(DATE_ATOM),
    'user' => $profile['user'],
    'settings' => ['window' => [$from, $to], 'days' => $days, 'seed' => $seed, 'reused_from' => $reuse ?: null,
        'random_share' => $reuse ? null : (float) $opt('random-share', '1.0'), 'engine_config' => $recCfg],
    'overview' => [
        'broad_candidates' => $group($sampleIds),
        'known_artist_releases' => ['found' => count($knownIds), 'already_in_sample' => count($knownDuplicates), 'extra' => count($knownExtra)],
        'known_artist_extra' => $group($knownExtra),
        'unique_releases' => $group(array_keys($all)),
    ],
    'counts' => [
        'pool_estimated' => $searchStats['exact_dated_estimated_total'] ?? null,
        'pool_reachable' => $searchStats['exact_dated_found'] ?? null,
        'candidates_found' => count($discovered),
        'known_artist_releases' => count($knownReleases),
        'known_artists_with_releases' => count(array_unique(array_map(static fn ($r) => $r['artist']['mbid'], $knownReleases))),
        'metadata_good' => $q['good'] ?? 0,
        'metadata_partial' => $q['partial'] ?? 0,
        'metadata_insufficient' => $q['insufficient'] ?? 0,
        'decisions' => $result ? $count(array_column($result['assessments'], 'decision')) : null,
    ],
    'bias' => [
        'candidate_sources' => $count(array_map($srcOf, array_keys($discovered))),
        'recommended_sources' => $result ? $count(array_map($srcOf, array_column($result['recommended'], 'id'))) : null,
        'wildcard_sources' => $result ? $count(array_map($srcOf, array_column($result['wildcards'], 'id'))) : null,
        'candidate_primary_families' => $result ? $count(array_map(static fn ($id) => $result['assessments'][$id]['primary_family'] ?? null, array_keys($discovered))) : null,
        'candidate_countries' => $count(array_column(array_values($discovered), 'country')),
    ],
    'candidate_search' => $searchStats,
    'known_artists' => $knownStats,
    'recommended' => $result['recommended'] ?? [],
    'wildcards' => $result['wildcards'] ?? [],
    'assessments' => $result['assessments'] ?? [],
    'releases' => array_values($all),
];

// --- Comparison with an earlier run (optional) -----------------------------------
if (($cmpFile = $opt('compare')) && $result) {
    $prev = json_decode((string) file_get_contents($cmpFile), true);
    $prevAll = array_column($prev['releases'], null, 'id');
    $label = static fn (array $r) => "{$r['artist']['name']} - {$r['title']}";
    $breadth = static function (array $families, int $total): array {
        $named = array_filter($families, static fn ($k) => $k !== '' && $k !== '-', ARRAY_FILTER_USE_KEY);
        arsort($named);
        $top = (int) reset($named);
        return ['families' => count($named), 'top_family' => array_key_first($named), 'top_share' => $total ? round($top / $total, 2) : 0, 'distribution' => $named];
    };
    $why = static function (string $id, string $list) use ($all, $result, $prevAll): string {
        if (!isset($all[$id])) {
            return 'not in the new sample (genre-neutral collection drew other releases)';
        }
        $a = $result['assessments'][$id];
        return match ($a['decision']) {
            'needs_date_check' => "release date not confirmed ({$all[$id]['date_check']['note']})",
            'wildcard' => 'moved to Wildcards',
            'recommended' => 'moved to Recommended',
            'excluded' => $a['note'] ?? 'excluded',
            'not_assessable' => 'no genre data',
            default => sprintf('still a candidate (fit %.2f) but ranked below the cut%s', $a['fit'], isset($a['not_selected']) ? " - {$a['not_selected']}" : ''),
        };
    };
    $whyNew = static fn (string $id) => isset($prevAll[$id]) ? 'was in the previous candidates, now ranked higher' : 'new in this sample';
    $diff = static function (array $old, array $new, string $list) use ($why, $whyNew, $label, $all, $prevAll): array {
        $oldIds = array_column($old, 'id');
        $newIds = array_column($new, 'id');
        return [
            'kept' => array_map(static fn ($id) => $label($all[$id]), array_values(array_intersect($oldIds, $newIds))),
            'dropped' => array_map(static fn ($id) => ['release' => $label($prevAll[$id]), 'why' => $why($id, $list)], array_values(array_diff($oldIds, $newIds))),
            'added' => array_map(static fn ($id) => ['release' => $label($all[$id]), 'why' => $whyNew($id)], array_values(array_diff($newIds, $oldIds))),
        ];
    };
    $prevC = $prev['counts'];
    $prevSample = count(array_filter($prev['releases'], static fn ($r) => !str_starts_with($r['source'], 'known_artist')));
    $out['comparison'] = [
        'previous_run' => $prev['generated_at'] . ' (' . $prev['experiment'] . ')',
        'releases' => ['previous' => ['broad_sample' => $prevSample, 'known_artist' => $prevC['known_artist_releases'], 'no_genre_data' => $prevC['metadata_insufficient']],
            'now' => ['broad_sample' => count($sampleIds), 'known_artist' => count($knownIds), 'no_genre_data' => $out['overview']['unique_releases']['missing_genre_data']]],
        'candidate_sources' => ['previous' => $prev['bias']['candidate_sources'], 'now' => $out['bias']['candidate_sources']],
        'genre_breadth' => [
            'previous' => $breadth($prev['bias']['candidate_primary_families'] ?? [], $prevSample),
            'now' => $breadth($out['bias']['candidate_primary_families'] ?? [], count($sampleIds)),
        ],
        'recommended' => $diff($prev['recommended'], $result['recommended'], 'recommended'),
        'wildcards' => $diff($prev['wildcards'], $result['wildcards'], 'wildcards'),
    ];
}

$dir = __DIR__ . '/../var/experiments/' . date('Ymd-His') . "-{$days}d" . ($reuse ? '-reuse' : '');
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create $dir\n");
    exit(1);
}
@file_put_contents(__DIR__ . '/../var/.htaccess', "Require all denied\n");
file_put_contents("$dir/result.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// --- 7. Report -------------------------------------------------------------------
ob_start();
$p = static fn (string $s = '') => print($s . "\n");
$factText = static function (array $r, array $ids): array {
    $byId = array_column($r['facts'], null, 'id');
    return array_map(static fn ($id) => "[$id] " . mb_strimwidth($byId[$id]['text'] ?? '?', 0, 150, '…') . ' (' . ($byId[$id]['source'] ?? '?') . ')', $ids);
};
$c = $out['counts'];
$p("MARTINS MUSIC RADAR - discovery experiment {$out['experiment']} - {$out['generated_at']}");
$p(str_repeat('=', 100));
$p("Window: $from .. $to ($days days)   Seed: $seed   Engine: rule-based PHP (no paid services)" . ($reuse ? '   [reused data]' : ''));
$p();
$o = $out['overview'];
$p('DATA OVERVIEW');
$p(sprintf('  MusicBrainz pool: %s new albums/EPs with an exact first-release day in the window (%s%% reachable via search)',
    $c['pool_reachable'] ?? '?', isset($c['pool_estimated'], $c['pool_reachable']) && $c['pool_estimated'] ? round($c['pool_reachable'] / $c['pool_estimated'] * 100) : '?'));
$p();
$p(sprintf('  %-34s %8s %6s %8s %13s %13s %10s', '', 'releases', 'good', 'partial', 'no genre data', 'date not day', 'ASSESSABLE'));
$row = static fn (string $label, array $g) => $p(sprintf('  %-34s %8d %6d %8d %13d %13d %10d', $label, $g['releases'], $g['metadata']['good'], $g['metadata']['partial'],
    $g['missing_genre_data'], $g['dates']['probable'] + $g['dates']['unconfirmed'], $g['assessable']));
$row('Broad sample (genre-neutral)', $o['broad_candidates']);
$row('+ extra releases by known artists', $o['known_artist_extra']);
$row('= unique releases', $o['unique_releases']);
$p(sprintf('  Known-artist releases found: %d (%d were already in the broad sample = duplicates, %d extra)',
    $o['known_artist_releases']['found'], $o['known_artist_releases']['already_in_sample'], $o['known_artist_releases']['extra']));
if ($c['decisions']) {
    $p('  Engine decisions: ' . json_encode($c['decisions']));
}

$p();
$p('NEW RELEASES FROM ARTISTS YOU KNOW (registered independently of the engine)');
$p(str_repeat('-', 100));
if (!$knownReleases) {
    $p('  none in this period');
}
foreach ($knownReleases as $r) {
    $a = $result['assessments'][$r['id']] ?? null;
    $dc = $all[$r['id']]['date_check'];
    $p(sprintf('  %-10s  %-26s %-34s %-6s [%s]  engine: %s', $r['first_release_date'], mb_strimwidth($r['artist']['name'], 0, 26, '…'), mb_strimwidth($r['title'], 0, 34, '…'), $r['type'], $r['watch_level'], $a['decision'] ?? '-'));
    if ($dc['status'] !== 'confirmed') {
        $p("              ! date {$dc['status']}: {$dc['note']}");
    }
}

if ($result) {
    $check = array_filter($result['assessments'], static fn ($a) => $a['decision'] === 'needs_date_check');
    $p();
    $p('NEEDS DATE CHECK (not presented as new until the release day is confirmed)');
    $p(str_repeat('-', 100));
    if (!$check) {
        $p('  none');
    }
    foreach ($check as $id => $a) {
        $r = $all[$id];
        $p(sprintf('  %-10s  %s - %s  [%s]  musical fit %.2f%s', $r['first_release_date'], $r['artist']['name'], $r['title'], $r['date_check']['status'], $a['fit'] ?? 0, isset($r['watch_level']) ? "  (known artist: {$r['watch_level']})" : ''));
        $p("              {$r['date_check']['note']}");
        if ($r['date_check']['release_dates']) {
            $p('              release dates on MusicBrainz: ' . implode(', ', $r['date_check']['release_dates']));
        }
    }
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
        $p(sprintf('%2d. %s - %s  (%s, %s)%s', $n + 1, $r['artist']['name'], $r['title'], $r['type'], $r['first_release_date'], $pick['angle'] ? "  [{$pick['angle']}]" : ''));
        $p('    Why: ' . $pick['why']);
        foreach ($pick['reasons'] as $reason) {
            $p('     - ' . $reason['text']);
            foreach ($factText($r, array_slice($reason['facts'], 0, 3)) as $f) {
                $p('         ' . $f);
            }
        }
        $s = $pick['signals'];
        $p(sprintf('    Signals: genre %.2f, family %.2f, neighbours %.2f, blend %.2f, novelty %.2f, outside %.2f | fit %.2f, confidence %.2f | source %s',
            $s['genre'], $s['family'], $s['neighbours'], $s['blend'], $s['novelty'], $s['outside'], $pick['fit'], $pick['confidence'], $r['source']));
        $p('    ' . $r['musicbrainz_url']);
    }
};
if ($result) {
    $show($result['recommended'], 'RECOMMENDED FOR YOU (no limit; known artists always included)');
    $show($result['wildcards'], 'WILDCARDS (max 5) - a documented bridge to your taste + something new');

    $p();
    $p('NEAR MISSES (best not selected - to understand the thresholds)');
    $p(str_repeat('-', 100));
    $near = array_filter($result['assessments'], static fn ($a) => $a['decision'] === 'not_selected');
    uasort($near, static fn ($a, $b) => $b['fit'] <=> $a['fit']);
    foreach (array_slice($near, 0, 8, true) as $id => $a) {
        $p(sprintf('  fit %.2f conf %.2f  %s - %s  [%s]%s', $a['fit'], $a['confidence'], $all[$id]['artist']['name'], $all[$id]['title'], $a['primary_family'] ?? '-', isset($a['not_selected']) ? "  ({$a['not_selected']})" : ''));
    }
}

$p();
$p('DATA QUALITY');
$p(str_repeat('-', 100));
$issues = [];
foreach ($all as $r) {
    foreach ($r['issues'] ?? [] as $iss) {
        $k = preg_replace('/: .*$/', '', $iss);
        $issues[$k] = ($issues[$k] ?? 0) + 1;
    }
}
arsort($issues);
foreach ($issues as $iss => $n) {
    $p("  $n x $iss");
}
$p('  Excluded while sampling: ' . json_encode($searchStats['excluded'] ?? []));

$p();
$p('BIAS CHECK');
$p(str_repeat('-', 100));
$p('  Candidate sources:   ' . json_encode($out['bias']['candidate_sources']));
$p('  Recommended sources: ' . json_encode($out['bias']['recommended_sources']) . '   Wildcards: ' . json_encode($out['bias']['wildcard_sources']));
$p('  Candidate families:  ' . json_encode(array_slice($out['bias']['candidate_primary_families'] ?? [], 0, 10)));
$p('  Candidate countries: ' . json_encode(array_slice($out['bias']['candidate_countries'], 0, 10)));
if (isset($searchStats['tags'])) {
    $p('  Tags (exact-dated in window -> picked): ' . implode(', ', array_map(static fn ($t, $s) => "$t " . ($s['exact_dated'] ?? $s['available'] ?? '?') . "->{$s['picked']}", array_keys($searchStats['tags']), $searchStats['tags'])));
}
if (isset($out['comparison'])) {
    $cm = $out['comparison'];
    $p();
    $p("CHANGES VS PREVIOUS RUN ({$cm['previous_run']})");
    $p(str_repeat('-', 100));
    $p('  Broad sample:   ' . $cm['releases']['previous']['broad_sample'] . ' -> ' . $cm['releases']['now']['broad_sample']
        . '   Known-artist releases: ' . $cm['releases']['previous']['known_artist'] . ' -> ' . $cm['releases']['now']['known_artist']
        . '   No genre data: ' . $cm['releases']['previous']['no_genre_data'] . ' -> ' . $cm['releases']['now']['no_genre_data']);
    $p('  Sources:        ' . json_encode($cm['candidate_sources']['previous']) . ' -> ' . json_encode($cm['candidate_sources']['now']));
    foreach (['previous', 'now'] as $k) {
        $b = $cm['genre_breadth'][$k];
        $p(sprintf('  Genres %-8s %d families, largest %s (%d%% of sample): %s', $k . ':', $b['families'], $b['top_family'], $b['top_share'] * 100, json_encode(array_slice($b['distribution'], 0, 8))));
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
        foreach ($d['added'] as $x) {
            $p("    + {$x['release']}: {$x['why']}");
        }
    }
}

$p();
$p("Saved: $dir/result.json");
$report = (string) ob_get_clean();
file_put_contents("$dir/report.txt", $report);
echo $report;
