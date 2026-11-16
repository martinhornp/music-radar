<?php

declare(strict_types=1);

/**
 * CLI: builds a genre profile from Last.fm tags for a user's top artists in
 * three periods (overall, 12 months, 3 months). Read-only; prints JSON to
 * stdout and stores nothing.
 *
 *   docker compose exec php php /var/www/api/bin/lastfm-genre-profile.php > genre-profile.json
 *
 * Two ways of measuring a genre's share in a period, so neither popularity
 * nor a few heavily played artists can dominate:
 *   breadth    every artist in the period counts equally
 *   listening  artists weighted by sqrt(plays) - more plays matter, but with
 *              strongly diminishing returns
 * An artist with several genres splits its weight across them (tag weights
 * from Last.fm, normalised per artist).
 *
 * Not reachable over HTTP (api/bin/ is denied). Never prints the API key.
 */

use MusicRadar\LastFm\LastFmClient;
use MusicRadar\LastFm\LastFmException;
use MusicRadar\Taste\TagClassifier;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$cfg = $app->config['lastfm'];
$user = (string) $cfg['username'];
$periods = ['overall', '12month', '3month'];

try {
    $client = new LastFmClient((string) $cfg['api_key']);

    // 1. Top 30 per period, merged without duplicates.
    $artists = [];
    foreach ($periods as $period) {
        foreach ($client->userTopArtists($user, $period, 30) as $i => $a) {
            $artists[$a['name']]['name'] = $a['name'];
            $artists[$a['name']]['periods'][$period] = ['rank' => $i + 1, 'plays' => $a['playcount']];
        }
    }

    // 2. Tags per artist (polite pace: Last.fm asks for max ~5 requests/s).
    foreach ($artists as $name => &$artist) {
        $result = $client->artistTopTags($name);
        usleep(250_000);
        $artist['lastfm_name'] = $result['artist'];
        $artist['raw_tags'] = array_slice($result['tags'], 0, 15);
        $artist += TagClassifier::split($result['tags'], $name);
    }
    unset($artist);
} catch (LastFmException $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// 3. Listening status - descriptive only. "Dormant" is not "disliked".
foreach ($artists as &$a) {
    $in = array_keys($a['periods']);
    $a['status'] = match (true) {
        count($in) === 3 => 'core',               // in all three periods
        isset($a['periods']['3month']) => 'current', // in the last 3 months
        isset($a['periods']['12month']) => 'recent', // last year, not the last 3 months
        default => 'dormant',                      // only in the all-time list
    };
    $a['families'] = [];
    foreach ($a['genres'] as $genre => $w) {
        $f = TagClassifier::family($genre);
        $a['families'][$f] = ($a['families'][$f] ?? 0) + $w;
    }
    arsort($a['families']);
}
unset($a);

// 4. Genre and family shares per period.
$aggregate = static function (array $members, string $field, ?string $period): array {
    $breadth = $listening = [];
    $n = count($members);
    $wsum = 0.0;
    foreach ($members as $a) {
        $wsum += $period ? sqrt($a['periods'][$period]['plays']) : 1;
    }
    foreach ($members as $a) {
        $w = $period ? sqrt($a['periods'][$period]['plays']) / $wsum : 1 / max($n, 1);
        foreach ($a[$field] as $g => $share) {
            $breadth[$g] = ($breadth[$g] ?? 0) + $share / max($n, 1);
            $listening[$g] = ($listening[$g] ?? 0) + $share * $w;
        }
    }
    arsort($breadth);
    arsort($listening);
    $round = static fn (array $x) => array_map(static fn ($v) => round($v * 100, 1), $x);
    return ['breadth' => $round($breadth), 'listening' => $round($listening)];
};

$byPeriod = [];
foreach ($periods as $period) {
    $members = array_filter($artists, static fn ($a) => isset($a['periods'][$period]) && $a['genres']);
    $byPeriod[$period] = [
        'artists' => count(array_filter($artists, static fn ($a) => isset($a['periods'][$period]))),
        'with_genres' => count($members),
        'genres' => $aggregate($members, 'genres', $period),
        'families' => $aggregate($members, 'families', $period),
    ];
}

$byStatus = [];
foreach (['core', 'current', 'recent', 'dormant'] as $status) {
    $members = array_filter($artists, static fn ($a) => $a['status'] === $status && $a['genres']);
    $byStatus[$status] = [
        'artists' => array_values(array_map(static fn ($a) => $a['name'], array_filter($artists, static fn ($a) => $a['status'] === $status))),
        'families' => $aggregate($members, 'families', null)['breadth'],
        'genres' => array_slice($aggregate($members, 'genres', null)['breadth'], 0, 8, true),
    ];
}

// 5. Data quality: what is missing or uncertain, and why.
$issues = [];
foreach ($artists as $a) {
    $why = [];
    if (!$a['raw_tags']) {
        $why[] = 'no tags on Last.fm';
    } elseif (!$a['genres']) {
        $why[] = 'no genre tags after filtering (only: ' . implode(', ', array_merge($a['meta'], $a['property'], $a['noise'], $a['unknown'], $a['low_support'])) . ')';
    } else {
        if (count($a['genres']) === 1) {
            $why[] = 'only one usable genre tag';
        }
        $topFamily = reset($a['families']);
        if (count($a['families']) >= 3 && $topFamily < 0.45) {
            $why[] = 'tags spread over unrelated styles (' . implode(', ', array_keys($a['families'])) . ') - possibly several artists sharing the name';
        }
        $heavy = ['Hardcore', 'Metalcore', 'Metal', 'Punk', 'Post-hardcore & emo'];
        $soft = ['Pop', 'Latin & reggaeton'];
        $heavyShare = array_sum(array_intersect_key($a['families'], array_flip($heavy)));
        $softShare = array_sum(array_intersect_key($a['families'], array_flip($soft)));
        if ($heavyShare >= 0.2 && $softShare >= 0.2) {
            $why[] = sprintf('tags mix heavy styles (%d%%) with pop (%d%%) - check for a name collision', $heavyShare * 100, $softShare * 100);
        }
        if (($a['raw_tags'][0]['count'] ?? 0) < 100) {
            $why[] = 'weak tagging';
        }
    }
    if (strcasecmp($a['lastfm_name'], $a['name']) !== 0) {
        $why[] = "Last.fm resolved the name to '{$a['lastfm_name']}'";
    }
    if ($why) {
        $issues[$a['name']] = $why;
    }
}

echo json_encode([
    'user' => $user,
    'fetched_at' => date(DATE_ATOM),
    'method' => [
        'min_tag_count' => TagClassifier::MIN_COUNT,
        'max_genres_per_artist' => 6,
        'breadth' => 'each artist counts equally',
        'listening' => 'artists weighted by sqrt(plays) within the period',
    ],
    'periods' => $byPeriod,
    'by_status' => $byStatus,
    'data_quality' => $issues,
    'artists' => array_values($artists),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
