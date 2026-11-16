<?php

declare(strict_types=1);

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
$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$discovery = new DiscoveryStore($db);
$runs = new RecommendationStore($db);
$runs->install();
$store = new TidalLinkStore($db);
$store->install();
$now = '2026-11-01T12:00:00+01:00';
$artist = 'bbbbbbbb-0000-0000-0000-000000000001';
$id = static fn ($n) => sprintf('aaaaaaaa-0000-0000-0000-%012d', $n);
$add = static function (int $n, string $date, string $seen, string $type = 'Album') use ($discovery, $artist, $id): string {
    $discovery->saveRelease(['id' => $id($n), 'title' => 'Album ' . $n, 'type' => $type, 'first_release_date' => $date,
        'artist' => ['mbid' => $artist, 'name' => 'Artist', 'primary_name' => 'Artist', 'credited_artists' => 1,
            'credits' => [['mbid' => $artist, 'name' => 'Artist', 'artist_name' => 'Artist', 'joinphrase' => '']]], 'release_tags' => []], 'pool', null, $seen);
    return $id($n);
};
$item = static fn (int $n, string $date, string $kind = 'recommended') => ['id' => $id($n), 'artist' => 'Artist', 'title' => 'Album ' . $n,
    'artistMbid' => $artist, 'releaseDate' => $date, 'type' => 'Album', 'score' => 0.4, 'pick' => ['kind' => $kind]];
$publish = static fn ($items) => $runs->publish(['generatedAt' => $now, 'window' => ['2026-10-02', '2026-11-01'],
    'examined' => count($items), 'taste' => [], 'releases' => $items], 'test', [], $now);
foreach ([[1, '2026-10-26'], [2, '2026-10-31'], [3, '2026-11-01'], [4, '2026-10-25'], [5, '2026-11-02'], [6, '2026-10-25']] as [$n, $date]) {
    $add($n, $date, '2026-10-20T10:00:00+02:00');
}
$run = $publish([$item(1, '2026-10-26'), $item(2, '2026-10-31', 'wildcard'), $item(3, '2026-11-01'),
    $item(4, '2026-10-25'), $item(5, '2026-11-02'), $item(6, '2026-11-01')]);
$originalFeed = $runs->activeFeed();
// Hundreds of historical releases and legacy negative results must not seed a backlog.
for ($n = 100; $n < 400; $n++) {
    $add($n, '2026-09-01', '2026-10-20T10:00:00+02:00');
}
$add(7, '2026-10-30', '2026-10-20T10:00:00+02:00'); // Recent but neither new nor recommended.
$store->sync($now);
$summary = $store->summary();
check('the initial cohort is frozen from the active run', $summary['run_id'] == $run && $summary['total'] === 3);
check('seven calendar days are inclusive and use actual stored release dates', $summary['from'] === '2026-10-26' && $summary['to'] === '2026-11-01'
    && $summary['total'] === 3);
check('Wildcards enter the same initial queue as Recommended', $db->query("SELECT priority FROM tidal_queue WHERE release_id='" . $id(2) . "'")->fetchColumn() == 2);
check('the one-time backfill includes older active picks without sweeping unrelated history', $store->summary(true)['total'] === 5
    && $store->summary(true)['run_id'] == $run && $db->query('SELECT COUNT(*) FROM tidal_queue')->fetchColumn() == 5);
$add(10, '2026-10-30', $now, 'EP');
$add(11, '2026-10-30', $now, 'Single');
$add(12, '2026-09-01', $now);
$store->sync($now);
check('a newly discovered non-recommended EP is automatically queued first', $store->due($now)['id'] === $id(10) && $store->due($now)['tidal_priority'] === 1);
check('singles and newly imported historical albums do not enter the new-music queue', $db->query('SELECT COUNT(*) FROM tidal_queue')->fetchColumn() == 6);
$store->save($store->due($now), ['status' => 'missing'], $now);
check('an old pending retry cannot hold up the seven-day cohort', $store->due($now)['tidal_priority'] === 2);
$add(13, '2026-10-31', $now);
$store->sync($now);
check('new discoveries preempt both pending cohort entries and due retries', $store->due('2026-11-02T12:00:00+01:00')['id'] === $id(13));
$store->save($store->due($now), ['status' => 'error'], $now);
check('an error is a delayed retry rather than an approved button', $store->attach([['id' => $id(13)]])[0]['tidalAlbumId'] === null);
check('a retry deadline does not block other work', $store->due($now)['tidal_priority'] === 2);
// Every initial member is checked once; later changes to the feed do not overwrite the cohort.
while (($row = $store->due($now)) !== null) {
    $store->save($row, ['status' => 'missing'], $now);
}
check('all initial members have a recorded result', $store->summary()['checked'] === 3 && $store->summary()['missing'] === 3);
check('link processing never changes scores or the active recommendation run', $runs->activeFeed() === $originalFeed && $runs->activeRun()['id'] == $run);
$add(8, '2026-10-10', '2026-10-20T10:00:00+02:00');
$publish([$item(7, '2026-10-30'), $item(8, '2026-10-10')]);
$store->sync($now);
check('newly relevant recent recommendations are added without re-seeding history', $store->due($now)['id'] === $id(7) && $store->summary()['run_id'] == $run && $store->summary()['total'] === 3);
check('later active runs cannot expand or restart the one-time backfill', $store->summary(true)['run_id'] == $run
    && $store->summary(true)['total'] === 5 && !$db->query("SELECT release_id FROM tidal_queue WHERE release_id='" . $id(8) . "'")->fetchColumn());
$store->save($store->due($now), ['status' => 'missing'], $now);
for ($n = 500; $n < 526; $n++) {
    $add($n, '2026-11-01', $now);
}
$store->sync($now);
for ($i = 0; $i < 25; $i++) {
    $store->save($store->due($now), ['status' => 'missing'], $now);
}
check('work continues beyond twenty checks on the same day', $store->due($now) !== null && $store->due($now)['tidal_priority'] === 1);
$store->save($store->due($now), ['status' => 'missing'], $now);
$times = [];
$mb = new MusicBrainzClient(transport: static function () use (&$times): array {
    $times[] = hrtime(true) / 1_000_000_000;
    return [200, '{"release-count":0,"releases":[]}', ''];
});
$import = new TidalImport($db, $mb);
foreach ([600, 601, 602, 603, 604] as $n) {
    $add($n, '2026-11-01', $now);
}
$zero = $import->step($now, 0);
check('an exhausted PHP budget registers discoveries but sends no MusicBrainz requests', $zero['checked'] === 0 && $zero['pending'] && $times === []);
$batch = $import->step($now, 18);
check('short batches are bounded and leave remaining work resumable', $batch['checked'] === 4 && $batch['pending'] && count($times) === 4);
check('MusicBrainz requests remain separated by at least 1.1 seconds', min([$times[1]-$times[0], $times[2]-$times[1], $times[3]-$times[2]]) >= 1.09);
$down = new MusicBrainzClient(maxAttempts: 1, transport: static fn () => [503, '', '']);
$error = (new TidalImport($db, $down))->step($now, 18);
check('a service outage records an error separately from missing links', $error['checked'] === 1 && $error['status'] === 'backoff'
    && $error['errors'][0]['code'] === 'http_503');
$add(700, '2026-11-01', $now);
$store->sync($now);
check('discoveries arriving during an outage are still ahead of failed retries', $store->due($now)['id'] === $id(700));

// Independent queues use the real matcher and persistent store, with no live HTTP.
$fixture = static function (int $count = 3) use ($now, $artist, $id): array {
    $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $discovery = new DiscoveryStore($db);
    $runs = new RecommendationStore($db);
    $runs->install();
    for ($n = 1; $n <= $count; $n++) {
        $discovery->saveRelease(['id' => $id($n), 'title' => 'Album ' . $n, 'type' => 'Album', 'first_release_date' => '2026-11-01',
            'artist' => ['mbid' => $artist, 'name' => 'Artist', 'primary_name' => 'Artist', 'credited_artists' => 1,
                'credits' => [['mbid' => $artist, 'name' => 'Artist', 'artist_name' => 'Artist', 'joinphrase' => '']]], 'release_tags' => []], 'pool', null, $now);
    }
    $store = new TidalLinkStore($db);
    $store->install();
    $store->sync($now);
    return [$db, $store];
};
$answer = static function (string $url) use ($artist): array {
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    $n = (int) substr($q['release-group'], -12);
    return [200, json_encode(['release-count' => 1, 'releases' => [[
        'id' => 'edition-' . $n, 'title' => 'Album ' . $n, 'date' => '2026-11-01', 'status' => 'Official',
        'artist-credit' => [['artist' => ['id' => $artist]]], 'media' => [['format' => 'Digital Media', 'track-count' => 8]],
        'relations' => [['type' => 'streaming', 'url' => ['resource' => 'https://tidal.com/album/' . (100 + $n)]]],
    ]]]), ''];
};
foreach (['http_404', 'network', 'invalid_json', 'exception'] as $failure) {
    [$db, $store] = $fixture();
    $calls = 0;
    $mb = new MusicBrainzClient(maxAttempts: 1, transport: static function ($url) use (&$calls, $failure, $answer): array {
        if (++$calls === 1) {
            return match ($failure) {
                'http_404' => [404, '{}', ''],
                'network' => [0, null, 'simulated timeout'],
                'invalid_json' => [200, 'broken response', ''],
                default => throw new RuntimeException('private diagnostic must not leak'),
            };
        }
        return $answer($url);
    });
    $result = (new TidalImport($db, $mb))->step($now, 18);
    $statuses = $db->query('SELECT status FROM tidal_links ORDER BY release_id')->fetchAll(PDO::FETCH_COLUMN);
    check("$failure on one release does not block the next two matches", $result['checked'] === 3 && !$result['pending']
        && $statuses === ['error', 'verified', 'verified']);
    $retry = $db->query('SELECT checked_at, next_check_at FROM tidal_links ORDER BY release_id')->fetch();
    check("$failure preserves an automatic retry without leaking exception text", $store->due($now) === null
        && strtotime($retry['next_check_at']) - strtotime($retry['checked_at']) === 86_400
        && $store->due(gmdate(DATE_ATOM, strtotime($retry['next_check_at']) - 1)) === null
        && ($store->due($retry['next_check_at'])['id'] ?? null) === $id(1)
        && !str_contains(json_encode($result), 'private diagnostic'));
}

foreach ([429, 503, 500] as $http) {
    [$db, $store] = $fixture();
    $calls = 0;
    $mb = new MusicBrainzClient(maxAttempts: 1, transport: static function ($url) use (&$calls, $http, $answer): array {
        return ++$calls === 1 ? [$http, '', '', 75] : $answer($url);
    });
    $result = (new TidalImport($db, $mb))->step($now, 18);
    check("HTTP $http pauses service traffic but leaves remaining work pending", $result['pending'] && $result['checked'] === 1
        && $result['retry_after'] === 75 && $result['errors'][0]['code'] === 'http_' . $http);
    $waiting = (new TidalImport($db, $mb))->step('2026-11-01T12:01:00+01:00', 18);
    check("HTTP $http backoff survives a new PHP importer and honors Retry-After", $waiting['pending']
        && $waiting['checked'] === 0 && $waiting['retry_after'] >= 15 && $waiting['retry_after'] <= 17 && $calls === 1);
    $resumed = (new TidalImport($db, $mb))->step('2026-11-01T12:01:16+01:00', 18);
    check("HTTP $http recovery processes untouched releases while the failed release waits", $resumed['checked'] === 2
        && !$resumed['pending'] && $db->query("SELECT COUNT(*) FROM tidal_links WHERE status='verified'")->fetchColumn() == 2);
    $retried = (new TidalImport($db, $mb))->step('2026-11-02T12:00:10+01:00', 18);
    check("HTTP $http failed release is automatically retried later", $retried['checked'] === 1
        && $db->query("SELECT COUNT(*) FROM tidal_links WHERE status='verified'")->fetchColumn() == 3);
}

[$db, $store] = $fixture();
$calls = 0;
$mb = new MusicBrainzClient(transport: static function ($url) use (&$calls, $answer): array {
    if (++$calls === 1) {
        $response = $answer($url);
        $body = json_decode($response[1], true);
        $body['releases'][0]['title'] .= ' Deluxe';
        return [200, json_encode($body), ''];
    }
    return $answer($url);
});
$result = (new TidalImport($db, $mb))->step($now, 18);
check('an ambiguous result is reported by release id', $result['ambiguous'] === [$id(1)]);
check('an unsafe edition is recorded and the next two releases are verified', $result['checked'] === 3
    && $db->query('SELECT status FROM tidal_links ORDER BY release_id')->fetchAll(PDO::FETCH_COLUMN) === ['ambiguous', 'verified', 'verified']);
check('unsafe matches never publish a button', $store->attach([['id' => $id(1)], ['id' => $id(2)]])[0]['tidalAlbumId'] === null
    && $store->attach([['id' => $id(2)]])[0]['tidalAlbumId'] === '102');

[$db, $store] = $fixture();
$first = $store->due($now);
$store->save($first, ['status' => 'verified', 'album' => '101', 'edition' => 'edition-1'], $now);
$interrupted = $store->due($now);
$store->reserve($interrupted['id'], $now); // Simulate termination during HTTP, before a checkpoint.
$result = (new TidalImport($db, new MusicBrainzClient(transport: $answer)))->step($now, 18);
check('after interruption a fresh importer preserves prior results and processes the next item', $result['checked'] === 1
    && $result['pending'] && $result['retry_after'] >= 117 && $result['retry_after'] <= 120
    && $store->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === '101');
$result = (new TidalImport($db, new MusicBrainzClient(transport: $answer)))->step('2026-11-01T12:02:01+01:00', 18);
check('the interrupted release is retried automatically after its reservation expires', $result['checked'] === 1
    && !$result['pending'] && $store->attach([['id' => $id(2)]])[0]['tidalAlbumId'] === '102');

[$db, $store] = $fixture(4);
$mb = new MusicBrainzClient(maxAttempts: 1, transport: static function ($url) use ($id, $answer): array {
    return str_contains($url, $id(1)) ? [0, null, 'timeout'] : $answer($url);
});
$result = (new TidalImport($db, $mb))->step($now, 18);
$result = (new TidalImport($db, $mb))->step('2026-11-02T12:00:01+01:00', 18);
check('a repeatedly failing release never rechecks or blocks successful peers', $result['checked'] === 1
    && $db->query('SELECT attempts FROM tidal_links ORDER BY release_id')->fetchAll(PDO::FETCH_COLUMN) === [2, 1, 1, 1]);
$row = $db->query('SELECT * FROM releases ORDER BY id')->fetch();
$store->save($row, ['status' => 'verified', 'album' => '101', 'edition' => 'edition-1'], $now);
$store->save($row, ['status' => 'error', 'error' => 'http_503'], $now);
check('a temporary error retains the previous approval and its diagnostic', $store->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === '101'
    && $db->query('SELECT last_error FROM tidal_links ORDER BY release_id')->fetchColumn() === 'http_503');

[$db, $store] = $fixture();
$row = $store->due($now);
$store->reserve($row['id'], $now);
$db->exec("CREATE TRIGGER interrupt_checkpoint BEFORE UPDATE ON tidal_queue BEGIN SELECT RAISE(ABORT, 'interrupted checkpoint'); END");
try {
    $store->save($row, ['status' => 'missing'], $now);
    check('an interrupted checkpoint raises an error', false);
} catch (PDOException) {
    check('an interrupted checkpoint rolls back both cache result and queue progress', $db->query('SELECT COUNT(*) FROM tidal_links')->fetchColumn() == 0
        && $db->query('SELECT first_checked_at FROM tidal_queue ORDER BY release_id')->fetchColumn() === null);
}

[$db, $store] = $fixture();
$requests = [];
$poison = new MusicBrainzClient(maxAttempts: 1, transport: static function ($url) use (&$requests, $id, $answer): array {
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    $requests[] = $q['release-group'];
    return $q['release-group'] === $id(1) ? [503, '', ''] : $answer($url);
});
// All three are already retries with identical dates and priority (missing: due after 3 days).
foreach ($db->query('SELECT * FROM releases')->fetchAll() as $row) {
    $store->save($row, ['status' => 'missing'], '2026-10-29T12:00:00+01:00');
}
$firstFailure = (new TidalImport($db, $poison))->step($now, 18);
$remaining = (new TidalImport($db, $poison))->step('2026-11-01T12:00:32+01:00', 18);
check('a repeatedly failing service lookup yields to other due retries after backoff', $firstFailure['checked'] === 1
    && $firstFailure['pending'] && $remaining['checked'] === 2 && !$remaining['pending']
    && $requests === [$id(1), $id(2), $id(3)]);
$secondFailure = (new TidalImport($db, $poison))->step('2026-11-02T12:00:10+01:00', 18);
check('a repeated service failure stays scheduled without undoing other results', $secondFailure['checked'] === 1
    && !$secondFailure['pending'] && $db->query("SELECT COUNT(*) FROM tidal_links WHERE status='verified'")->fetchColumn() == 2);

[$db, $store] = $fixture();
$runs = new RecommendationStore($db);
$run = $runs->publish(['generatedAt' => $now, 'window' => ['2026-10-02', '2026-11-01'], 'examined' => 3,
    'taste' => [], 'releases' => [$item(1, '2026-11-01'), $item(2, '2026-11-01'), $item(3, '2026-11-01')]], 'test', [], $now);
$db->exec("CREATE TRIGGER interrupt_seed BEFORE INSERT ON tidal_queue WHEN NEW.release_id='" . $id(2)
    . "' BEGIN SELECT RAISE(ABORT, 'interrupted seed'); END");
try {
    $store->sync($now);
    check('an interrupted initial cohort raises an error', false);
} catch (PDOException) {
    check('an interrupted initial cohort rolls back its marker and partial registration', $db->query('SELECT seed_run_id FROM tidal_state')->fetchColumn() === null
        && $db->query('SELECT COUNT(*) FROM tidal_queue WHERE seed_run_id IS NOT NULL')->fetchColumn() == 0);
}
$db->exec('DROP TRIGGER interrupt_seed');
$store->sync($now);
check('initial cohort registration resumes completely after interruption', $store->summary()['total'] === 3 && $store->summary()['run_id'] == $run);

[$db, $store] = $fixture(1);
check('consecutive service failures use bounded exponential backoff', $store->pause($now, 0) === 30
    && $store->pause($now, 0) === 60 && $store->pause($now, 0) === 120);
for ($n = 0; $n < 10; $n++) {
    $delay = $store->pause($now, 0);
}
check('default service backoff caps at fifteen minutes but honors longer server instructions', $delay === 900 && $store->pause($now, 1800) === 1800);
$store->serviceRecovered();
check('service recovery resets the persisted failure streak', $store->pauseRemaining($now) === 0 && $store->pause($now, 0) === 30);
$store->serviceRecovered();
$row = $store->due($now);
$store->save($row, ['status' => 'verified', 'album' => '101', 'edition' => 'edition-1'], $now);
$requests = 0;
$approvedImport = new TidalImport($db, new MusicBrainzClient(maxAttempts: 1, transport: static function () use (&$requests) {
    $requests++;
    return [503, '', ''];
}));
$result = $approvedImport
    ->step('2026-11-08T12:00:10+01:00', 18);
check('an overdue approved link never causes a MusicBrainz request', $result['checked'] === 0
    && !$result['pending'] && $requests === 0 && $store->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === '101');
$store->save($row, ['status' => 'error', 'error' => 'http_503'], $now);
$db->exec("UPDATE releases SET data_hash='changed-tags-only'");
$result = $approvedImport->step('2026-11-09T12:00:00+01:00', 18);
check('retained approval skips old failed refreshes and irrelevant metadata changes', $result['checked'] === 0
    && !$result['pending'] && $requests === 0 && $store->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === '101');
$db->exec("UPDATE releases SET title='Different album'");
$result = $approvedImport->step('2026-11-10T12:00:00+01:00', 18);
check('a changed identity hides the old approval without another automatic lookup', $result['checked'] === 0
    && $requests === 0 && $store->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === null);

[$db, $store] = $fixture(1);
$result = (new TidalImport($db, new MusicBrainzClient(transport: $answer)))->step('2026-11-01T00:05:00+01:00', 18);
check('progress clocks preserve the local release day near midnight', $result['checked'] === 1 && !$result['pending']);

[$db, $store] = $fixture();
$runs = new RecommendationStore($db);
$run = $runs->publish(['generatedAt' => $now, 'window' => ['2026-10-02', '2026-11-01'], 'examined' => 3,
    'taste' => [], 'releases' => [$item(1, '2026-11-01'), $item(2, '2026-11-01', 'wildcard'), $item(3, '2026-11-01')]], 'test', [], $now);
$beforeFeed = $runs->activeFeed();
foreach ($db->query('SELECT * FROM releases ORDER BY id')->fetchAll() as $n => $row) {
    $store->save($row, match ($n) {
        0 => ['status' => 'verified', 'album' => '101', 'edition' => 'edition-1'],
        1 => ['status' => 'missing'],
        default => ['status' => 'error', 'error' => 'http_503'],
    }, $now);
}
$beforeCache = $db->query('SELECT * FROM tidal_links ORDER BY release_id')->fetchAll();
$db->exec("DELETE FROM tidal_queue WHERE release_id IN ('" . $id(1) . "','" . $id(2) . "')");
$store->pause($now, 300);
$store->sync($now);
check('backfill registration preserves cached approvals, negative results and their retry dates', $beforeCache === $db->query('SELECT * FROM tidal_links ORDER BY release_id')->fetchAll()
    && $store->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === '101' && $store->due($now) === null);
check('previous cache results without queue rows are adopted without another first lookup', $store->summary(true)['checked'] === 3
    && $store->summary(true)['verified'] === 1 && $store->summary(true)['missing'] === 1 && $store->summary(true)['error'] === 1);
check('backfill registration preserves service backoff and new-discovery priority', $store->pauseRemaining($now) === 300
    && $db->query("SELECT priority FROM tidal_queue WHERE release_id='" . $id(3) . "'")->fetchColumn() == 1);
check('backfill registration never changes the active run or scores', $runs->activeFeed() === $beforeFeed);
$store->sync($now);
check('repeated sync does not duplicate backfill entries or invalidate cached links', $store->summary(true)['total'] === 3
    && $beforeCache === $db->query('SELECT * FROM tidal_links ORDER BY release_id')->fetchAll());

[$db, $store] = $fixture();
$runs = new RecommendationStore($db);
$run = $runs->publish(['generatedAt' => $now, 'window' => ['2026-10-02', '2026-11-01'], 'examined' => 3,
    'taste' => [], 'releases' => [$item(1, '2026-11-01'), $item(2, '2026-11-01'), $item(3, '2026-11-01')]], 'test', [], $now);
$db->prepare('UPDATE tidal_state SET seed_run_id=?')->execute([$run]);
$db->exec("CREATE TRIGGER interrupt_backfill BEFORE INSERT ON tidal_queue WHEN NEW.release_id='" . $id(2)
    . "' BEGIN SELECT RAISE(ABORT, 'interrupted backfill'); END");
try {
    $store->sync($now);
    check('an interrupted backfill registration raises an error', false);
} catch (PDOException) {
    check('backfill marker and all cohort registrations roll back together on interruption', $db->query('SELECT backfill_run_id FROM tidal_state')->fetchColumn() === null
        && $db->query('SELECT COUNT(*) FROM tidal_queue WHERE backfill_run_id IS NOT NULL')->fetchColumn() == 0);
}
$db->exec('DROP TRIGGER interrupt_backfill');
$store->sync($now);
check('an interrupted backfill registration resumes completely', $store->summary(true)['total'] === 3 && $store->summary(true)['run_id'] == $run);

[$db, $store] = $fixture(1);
$store->save($store->due($now), ['status' => 'missing'], $now);
$db->exec("UPDATE tidal_links SET status='candidate'"); // Legacy manual-review metadata is not an automatic result.
$db->exec('DELETE FROM tidal_queue');
$runs = new RecommendationStore($db);
$runs->publish(['generatedAt' => $now, 'window' => ['2026-10-02', '2026-11-01'], 'examined' => 1,
    'taste' => [], 'releases' => [$item(1, '2026-11-01')]], 'test', [], $now);
$store->sync($now);
check('legacy candidates enter automatic validation rather than count as finished matches', $store->summary(true)['pending'] === 1
    && $store->summary(true)['checked'] === 0 && $store->due($now)['id'] === $id(1));

// Date stages include non-recommended releases, and overlapping stages reuse all prior evidence.
$stageFixture = static function () use ($fixture, $id, $now): array {
    [$db, $store] = $fixture(9);
    $db->exec("UPDATE releases SET first_seen_at='2026-10-01T00:00:00+02:00'");
    $update = $db->prepare('UPDATE releases SET first_release_date=?, type=? WHERE id=?');
    foreach ([[2, '2026-10-30', 'Album'], [3, '2026-10-29', 'Album'], [4, '2026-10-25', 'EP'],
        [5, '2026-10-24', 'Album'], [6, '2026-11-02', 'Album'], [7, '2026-10', 'Album'], [8, '2026-10-30', 'Single'],
        [9, '2026-10-31', 'Album']] as [$n, $date, $type]) {
        $update->execute([$date, $type, $id($n)]);
    }
    $row = $db->query('SELECT * FROM releases ORDER BY id')->fetch();
    $store->save($row, ['status' => 'verified', 'album' => '101', 'edition' => 'edition-1'], '2026-10-23T12:00:00+02:00');
    $s = $db->prepare('SELECT * FROM releases WHERE id=?');
    $s->execute([$id(9)]);
    $store->save($s->fetch(), ['status' => 'missing'], $now);
    $db->exec('DELETE FROM tidal_queue');
    return [$db, $store];
};
$stageAnswer = static function (PDO $db) use ($answer): Closure {
    return static function ($url) use ($db, $answer): array {
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $s = $db->prepare('SELECT title, first_release_date FROM releases WHERE id=?');
        $s->execute([$q['release-group']]);
        $row = $s->fetch();
        $response = $answer($url);
        $body = json_decode($response[1], true);
        $body['releases'][0]['date'] = $row['first_release_date'];
        $body['releases'][0]['title'] = $row['title'];
        return [200, json_encode($body), ''];
    };
};
[$db, $store] = $stageFixture();
$requests = [];
$respond = $stageAnswer($db);
$mb = new MusicBrainzClient(transport: static function ($url) use (&$requests, $respond): array {
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    $requests[] = $q['release-group'];
    return $respond($url);
});
$beforeCache = $db->query('SELECT * FROM tidal_links ORDER BY release_id')->fetchAll();
$result = (new TidalImport($db, $mb))->step($now, 18, '2026-10-30');
check('a date stage selects all stored Albums/EPs from the inclusive cutoff through today', $result['stage']['total'] === 3
    && $result['stage']['approved'] === 2 && $result['stage']['missing'] === 1 && $requests === [$id(2)]);
check('overdue approved links and fresh negative results cause no extra lookup', $result['checked'] === 1 && !$result['pending']
    && $result['stage']['waiting_retry'] === 1 && $db->query('SELECT * FROM tidal_links ORDER BY release_id LIMIT 1')->fetch() === $beforeCache[0]);
$result = (new TidalImport($db, $mb))->step($now, 18, '2026-10-30');
check('repeating the same date stage performs no duplicate lookup', $result['checked'] === 0 && count($requests) === 1);
$result = (new TidalImport($db, $mb))->step($now, 18, '2026-10-25');
check('moving the cutoff backwards checks only newly included unresolved releases', $result['checked'] === 2
    && $result['stage']['total'] === 5 && $result['stage']['approved'] === 4 && $requests === [$id(2), $id(3), $id(4)]);
check('date-stage queue entries retain their lower priority than new discoveries', $db->query("SELECT priority FROM tidal_queue WHERE release_id='" . $id(4) . "'")->fetchColumn() == 4);
$db->exec("UPDATE releases SET data_hash='changed-tags-only'");
$result = (new TidalImport($db, $mb))->step($now, 18, '2026-10-25');
check('irrelevant metadata changes do not recheck approved date-stage links', $result['checked'] === 0 && count($requests) === 3);
$db->prepare('UPDATE releases SET title=? WHERE id=?')->execute(['Album 1 (Changed edition)', $id(1)]);
$result = (new TidalImport($db, $mb))->step($now, 18, '2026-10-30');
check('a date stage never rechecks an old approval even after an identity change', $result['checked'] === 0
    && count($requests) === 3 && $store->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === null);

[$db, $store] = $fixture(1);
$db->exec("UPDATE releases SET first_release_date='2026-09-25', first_seen_at='2026-09-01T00:00:00+02:00'");
$row = $db->query('SELECT * FROM releases')->fetch();
$store->save($row, ['status' => 'error', 'error' => 'http_503'], $now);
$db->exec('DELETE FROM tidal_queue');
$respond = $stageAnswer($db);
$import = new TidalImport($db, new MusicBrainzClient(transport: $respond));
$result = $import->step($now, 18, '2026-09-25');
check('an older failed request keeps its retry deadline when selected by date', $result['checked'] === 0
    && $result['stage']['error'] === 1 && $result['stage']['waiting_retry'] === 1 && $store->due($now) === null);
$result = $import->step('2026-11-02T12:00:10+01:00', 18);
check('the daily import never retries a release over 30 days old, even one from a backfill', $result['checked'] === 0
    && $store->due('2026-11-10T12:00:00+01:00') === null);

[$db, $store] = $fixture(1);
$db->exec("UPDATE releases SET first_release_date='2026-09-25', first_seen_at='2026-09-01T00:00:00+02:00'");
$db->exec('DELETE FROM tidal_queue');
$result = (new TidalImport($db, new MusicBrainzClient(maxAttempts: 1, transport: static fn () => [503, '', ''])))
    ->step($now, 18, '2026-09-25');
check('a failed lookup in an older date stage is not picked up by the daily import', $result['checked'] === 1
    && $store->due('2026-11-02T12:00:10+01:00') === null);
$result = (new TidalImport($db, new MusicBrainzClient(transport: $stageAnswer($db))))->step('2026-11-02T12:00:10+01:00', 18, '2026-09-25');
check('...the backfill workflow (a date stage) is what checks it again', $result['stage'] !== null);

[$db, $store] = $stageFixture();
$db->exec("CREATE TRIGGER interrupt_date_stage BEFORE INSERT ON tidal_queue WHEN NEW.release_id='" . $id(9)
    . "' BEGIN SELECT RAISE(ABORT, 'interrupted date stage'); END");
try {
    $store->enqueueFrom('2026-10-30', $now);
    check('an interrupted date registration raises an error', false);
} catch (PDOException) {
    check('date-stage queue registration rolls back completely on interruption', $db->query('SELECT COUNT(*) FROM tidal_queue')->fetchColumn() == 0);
}
$db->exec('DROP TRIGGER interrupt_date_stage');
$store->enqueueFrom('2026-10-30', $now);
check('an interrupted date-stage registration is safely repeatable', $store->due($now, from: '2026-10-30')['id'] === $id(2));

foreach (['2026-02-30', '2026-2-03', ' 2026-10-30', '9999-01-01', ''] as $from) {
    try {
        TidalLinkStore::validateFrom($from, $now);
        check("invalid cutoff '$from' is rejected", false);
    } catch (InvalidArgumentException) {
        check("invalid cutoff '$from' is rejected", true);
    }
}
$token = str_repeat('stage-test-', 4);
$controller = new MusicRadar\Controllers\InternalController(['import' => ['token' => $token]],
    static fn () => throw new RuntimeException('invalid date must not access the database'), dirname(__DIR__));
foreach (['2026-02-30', '9999-01-01', [], 20261030, null] as $from) {
    $response = $controller->import(new MusicRadar\Http\Request('POST', '/internal/import', body: json_encode(['tidal_from' => $from]),
        headers: ['x-import-token' => $token]));
    check('the internal API rejects invalid date input before running the import', $response->status === 400);
}
$response = $controller->import(new MusicRadar\Http\Request('POST', '/internal/import', body: '{"tidal_from":"2026-10-30"}'));
check('date backfills require the existing import-token authorization', $response->status === 404);
foreach ([['tidal_from' => '2026-10-30', 'tidal_recheck' => 'everything', 'tidal_since' => '2026-11-01T12:00:00Z'],
    ['tidal_from' => '2026-10-30', 'tidal_recheck' => 'missing'],
    ['tidal_from' => '2026-10-30', 'tidal_recheck' => 'missing', 'tidal_since' => '9999-01-01T00:00:00Z'],
    ['tidal_from' => '2026-10-30', 'tidal_recheck' => 'missing', 'tidal_since' => 'yesterday'],
    ['tidal_recheck' => 'missing', 'tidal_since' => '2026-11-01T12:00:00Z']] as $body) {
    $response = $controller->import(new MusicRadar\Http\Request('POST', '/internal/import', body: json_encode($body), headers: ['x-import-token' => $token]));
    check('the internal API rejects an invalid recheck before running the import: ' . json_encode($body), $response->status === 400);
}

[$db, $store] = $stageFixture();
$db->exec('DROP TABLE recommendation_items');
$db->exec('DROP TABLE recommendation_scores');
$db->exec('DROP TABLE recommendation_runs');
$db->exec('DROP TABLE profiles');
$controller = new MusicRadar\Controllers\InternalController(['import' => ['token' => $token, 'budget_seconds' => 0]], static fn () => $db, dirname(__DIR__));
$response = $controller->import(new MusicRadar\Http\Request('POST', '/internal/import', body: '{"tidal_from":"2026-10-30"}',
    headers: ['x-import-token' => $token]));
check('the authenticated date request runs only TIDAL even with no music profile or recommendation tables', $response->status === 200
    && $response->data['mode'] === 'tidal_only' && $response->data['tidal']['checked'] === 0 && !$response->data['done']);
check('a TIDAL-only date request does not create music profiles or recommendation tables', $db->query("SELECT COUNT(*) FROM sqlite_master
    WHERE name IN ('profiles','recommendation_items','recommendation_scores','recommendation_runs')")->fetchColumn() == 0);

// Rollout from the deployed schema preserves previous approvals, including before import migration.
$legacy = new PDO('sqlite::memory:');
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$legacy->exec('CREATE TABLE releases (id TEXT PRIMARY KEY, title TEXT, type TEXT, first_release_date TEXT, artist_mbid TEXT, data_hash TEXT)');
$legacy->exec('CREATE TABLE release_artists (release_id TEXT, position INTEGER, artist_mbid TEXT)');
$legacy->exec('CREATE TABLE tidal_links (release_id TEXT PRIMARY KEY, release_hash TEXT, status TEXT, album_id TEXT, edition_id TEXT,
    approval_hash TEXT, checked_at TEXT, next_check_at TEXT, attempts INTEGER, daily_checks INTEGER)');
$legacy->prepare('INSERT INTO releases VALUES (?, ?, ?, ?, ?, ?)')->execute([$id(1), 'Old album', 'Album', '2025-01-01', $artist, str_repeat('a', 40)]);
$legacy->prepare('INSERT INTO release_artists VALUES (?, 0, ?)')->execute([$id(1), $artist]);
$legacy->prepare('INSERT INTO tidal_links VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1)')->execute([$id(1), str_repeat('a', 40), 'verified', '123', 'edition', 'old-code-hash', '2026-01-01T00:00:00Z', '2026-01-08T00:00:00Z']);
$old = new TidalLinkStore($legacy);
check('legacy approved links stay visible before the first import migrates columns', $old->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === '123');
$old->install();
$legacy->exec("UPDATE releases SET data_hash='changed-tags-only'");
check('migration preserves legacy approval independently of subsequent irrelevant changes', $old->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === '123');
check('migrating older approvals does not queue the historic catalog', $legacy->query('SELECT COUNT(*) FROM tidal_queue')->fetchColumn() == 0);
$legacy->exec("UPDATE releases SET first_release_date='2025-02-01'");
check('a real identity change hides an old approval even without background backfill', $old->attach([['id' => $id(1)]])[0]['tidalAlbumId'] === null);
$legacy->exec('ALTER TABLE releases ADD COLUMN first_seen_at TEXT');
$old->sync($now);
check('an identity conflict never restarts lookups for a legacy approved album', $old->due($now) === null);

// New releases before retries, whatever queued them; counts for the log.
[$db, $store] = $fixture();
$store->save($db->query("SELECT * FROM releases WHERE id='" . $id(1) . "'")->fetch(), ['status' => 'missing'], '2026-10-20T12:00:00+01:00');
$db->exec("UPDATE tidal_queue SET priority=4 WHERE release_id='" . $id(2) . "'"); // e.g. queued by a date backfill
check('a release never checked goes before a due retry, whatever queued it', in_array($store->due($now)['id'], [$id(2), $id(3)], true));
check('the log knows what is left: new releases and retries', $store->dueCounts($now) === ['new' => 2, 'retries' => 1]);
$step = (new TidalImport($db, new MusicBrainzClient(transport: $answer)))->step($now, 18);
check('a step reports what it checked and what is left', $step['checked_new'] === 2 && $step['checked_retries'] === 1
    && $step['results'] === ['verified' => 3, 'missing' => 0, 'ambiguous' => 0, 'error' => 0] && $step['queue'] === ['new' => 0, 'retries' => 0]);
echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
