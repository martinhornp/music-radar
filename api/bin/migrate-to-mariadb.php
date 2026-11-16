<?php

declare(strict_types=1);

/**
 * One-time, restartable migration of the local discovery data to MariaDB:
 * the SQLite store (releases, artists, slices, failures, meta), the music
 * profile and the current recommendations (feed.json) as the first run.
 * Feedback is already in MariaDB and is never touched.
 *
 *   php api/bin/migrate-to-mariadb.php --target=db
 *       the configured database (locally e.g. DB_NAME=music_radar_test)
 *   php api/bin/migrate-to-mariadb.php --target=https://musik.hornpedersen.dk
 *       production, through POST /api/internal/migrate; the token is read
 *       from the IMPORT_TOKEN environment variable or api/var/import-token
 *
 * Options: --source=api/var/discovery.sqlite --profile=api/var/profile.json
 *          --feed=api/data/feed.json --batch=300 --pause=2 (seconds between calls)
 *
 * Every row is written by primary key, so running it again gives the same
 * result (no duplicates). At the end the row counts and fingerprints of
 * every table are compared with the source; the run fails if they differ.
 */

use MusicRadar\Database\Database;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Feedback\FeedbackSet;
use MusicRadar\Feedback\Schema;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$var = __DIR__ . '/../var';
$opt = static function (string $name, ?string $default = null) use ($argv): ?string {
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen($name) + 3);
        }
    }
    return $default;
};
$say = static fn (string $m) => fwrite(STDOUT, $m . PHP_EOL);
$target = (string) $opt('target', '');
$batch = max(1, (int) $opt('batch', '300'));
$pause = (float) $opt('pause', '2');

$source = new DiscoveryStore(new PDO('sqlite:' . $opt('source', "$var/discovery.sqlite")));
$profile = json_decode((string) @file_get_contents($opt('profile', "$var/profile.json")), true);
$feed = json_decode((string) @file_get_contents($opt('feed', __DIR__ . '/../data/feed.json')), true);
if (!is_array($profile) || !is_array($feed)) {
    fwrite(STDERR, "Profile or feed.json not readable.\n");
    exit(1);
}

// --- target: the database directly, or the site's internal endpoint ---------------
if ($target === 'db') {
    if (!Database::isConfigured($app->config['db'])) {
        fwrite(STDERR, "No database configured.\n");
        exit(1);
    }
    $pdo = Database::connect($app->config['db']);
    $store = new DiscoveryStore($pdo);
    $feeds = new RecommendationStore($pdo);
    $feeds->install();
    Schema::ensure($pdo);
    $label = "database {$app->config['db']['name']}";
    $counts = static fn () => ['counts' => $store->counts(), 'fingerprints' => $store->fingerprints()];
    $rows = static fn (string $table, array $rows) => $store->importRows($table, $rows);
    $saveProfile = static fn (array $p) => $store->saveProfile($p, 'migration', date(DATE_ATOM));
    $publish = static fn (array $f) => $feeds->activeRun() !== null
        ? ['published' => false, 'reason' => 'the database already has active recommendations']
        : ['published' => true, 'run_id' => $feeds->publish($f, 'migration', ['source' => 'feed.json'], date(DATE_ATOM))];
    $feedbackCount = static fn () => FeedbackSet::fromRepository(new FeedbackRepository($pdo))->summary();
} elseif (preg_match('#^https?://#', $target)) { // http only for local tests
    $token = getenv('IMPORT_TOKEN') ?: trim((string) @file_get_contents("$var/import-token"));
    if (strlen($token) < 32) {
        fwrite(STDERR, "No import token (IMPORT_TOKEN or api/var/import-token).\n");
        exit(1);
    }
    $base = rtrim($target, '/') . '/api';
    $label = $target;
    $call = static function (string $method, string $path, ?array $body = null) use ($base, $token, $pause): array {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $ctx = stream_context_create(['http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-Import-Token: $token\r\nUser-Agent: MartinsMusicRadar migration\r\n",
                'content' => $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => 120,
                'ignore_errors' => true,
            ]]);
            $res = @file_get_contents($base . $path, false, $ctx);
            $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
            usleep((int) ($pause * 1_000_000)); // stay below the host's request limit
            if ($status === 429 || $status === 503) {
                sleep(10 * $attempt);
                continue;
            }
            $data = json_decode((string) $res, true);
            if ($status !== 200 || !is_array($data)) {
                throw new RuntimeException("$method $path: HTTP $status " . mb_substr((string) $res, 0, 200));
            }
            return $data;
        }
        throw new RuntimeException("$method $path: still rate limited");
    };
    $counts = static fn () => $call('POST', '/internal/migrate', ['action' => 'counts']);
    $rows = static fn (string $table, array $rows) => $call('POST', '/internal/migrate', ['action' => 'rows', 'table' => $table, 'rows' => $rows])['written'];
    $saveProfile = static fn (array $p) => $call('POST', '/internal/profile', $p)['id'];
    $publish = static fn (array $f) => $call('POST', '/internal/migrate', ['action' => 'feed', 'feed' => $f]);
    $feedbackCount = static fn () => FeedbackSet::fromUrl($target . '/api/feedback')->summary();
} else {
    fwrite(STDERR, "Use --target=db or --target=https://...\n");
    exit(2);
}

try {
    $t0 = microtime(true);
    $src = ['counts' => $source->counts(), 'fingerprints' => $source->fingerprints()];
    $before = $counts();
    $feedbackBefore = $feedbackCount();
    $say("Migrating to $label");
    $say('  source rows:  ' . json_encode($src['counts']));
    $say('  target before: ' . json_encode($before['counts']));
    $say('  feedback before: ' . json_encode($feedbackBefore));

    foreach (array_keys(DiscoveryStore::COPYABLE) as $table) {
        $written = 0;
        for ($offset = 0; ($page = $source->rows($table, $offset, $batch)) !== []; $offset += $batch) {
            $written += $rows($table, $page);
        }
        $say(sprintf('  %-9s %6d rows written', $table, $written));
    }

    if (($before['counts']['profiles'] ?? 0) === 0) {
        $say('  profile:  saved as id ' . $saveProfile($profile) . ' (' . count($profile['artists']) . ' artists)');
    } else {
        $say('  profile:  target already has one - kept');
    }
    $pub = $publish($feed);
    $say('  recommendations: ' . ($pub['published'] ? "published feed.json as run {$pub['run_id']}" : $pub['reason']));

    $after = $counts();
    $feedbackAfter = $feedbackCount();
    $say('  target after:  ' . json_encode($after['counts']));
    $ok = true;
    foreach (array_keys(DiscoveryStore::COPYABLE) as $table) {
        $same = $after['counts'][$table] === $src['counts'][$table] && $after['fingerprints'][$table] === $src['fingerprints'][$table];
        $say(sprintf('  check %-9s source %6d  target %6d  %s', $table, $src['counts'][$table], $after['counts'][$table],
            $same ? 'identical' : 'DIFFERENT'));
        $ok = $ok && $same;
    }
    $sameFeedback = $feedbackAfter === $feedbackBefore;
    $say('  feedback after: ' . json_encode($feedbackAfter) . ($sameFeedback ? ' (unchanged)' : ' CHANGED'));
    $say(sprintf('Done in %.1f s - %s', microtime(true) - $t0, $ok && $sameFeedback ? 'verified' : 'VERIFICATION FAILED'));
} catch (Throwable $e) {
    // e.g. HTTP 404 = wrong or missing token, or the endpoint is not deployed yet
    fwrite(STDERR, 'Migration stopped: ' . $e->getMessage() . "\nNothing is lost - it can be run again.\n");
    exit(1);
}
exit($ok && $sameFeedback ? 0 : 1);
