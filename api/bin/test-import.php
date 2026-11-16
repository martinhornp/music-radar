<?php

declare(strict_types=1);

/**
 * Tests for the server import (ImportRunner + MariaDB), against a SEPARATE
 * test database (default music_radar_test - its tables are dropped and
 * recreated) and a simulated MusicBrainz / Last.fm that the test can change:
 * new and late registrations, corrected dates, outages, slow answers.
 *
 *   docker compose exec php php /var/www/api/bin/test-import.php
 */

use MusicRadar\Database\Database;
use MusicRadar\Discovery\DiscoveryPipeline;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Feed\CurrentFeed;
use MusicRadar\Feed\FeedFile;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Feedback\FeedbackChange;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Feedback\FeedbackSet;
use MusicRadar\Feedback\Schema;
use MusicRadar\Import\ImportRunner;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\Recommend\ArtistInterest;
use MusicRadar\Recommend\RecommendationEngine;
use MusicRadar\Recommend\SimilarArtists;
use MusicRadar\Recommend\TasteModel;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$dbCfg = ['name' => getenv('TEST_DB_NAME') ?: 'music_radar_test'] + $app->config['db'];
if (!str_ends_with($dbCfg['name'], '_test')) {
    fwrite(STDERR, "Refusing to run: the test database name must end in _test.\n");
    exit(2);
}
$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " - $detail") . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

$pdo = Database::connect($dbCfg);
foreach (['releases', 'artists', 'slices', 'failures', 'meta', 'profiles', 'recommendation_runs', 'recommendation_items', 'import_log', 'release_feedback', 'release_feedback_log', 'artist_similar'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}

// --- a small simulated world ---------------------------------------------------------
$today = '2026-10-26';
$day = static fn (int $daysAgo) => date('Y-m-d', strtotime("$today -$daysAgo days"));
$uuid = static fn (string $prefix, int $n) => sprintf('%s-0000-0000-0000-%012d', $prefix, $n);
$A = $uuid('aaaaaaaa', 1); // followed hardcore band
$B = $uuid('aaaaaaaa', 2); // interested pop artist
$C = $uuid('cccccccc', 1); // unknown hardcore band
$D = $uuid('cccccccc', 2); // unknown pop act
$E = $uuid('aaaaaaaa', 3); // interested artist whose music scores badly (classical)
$F = $uuid('cccccccc', 3); // unknown artist with the same kind of music
$names = [$A => 'Knock Band', $B => 'Pop Person', $C => 'New Hardcore Band', $D => 'Glitter Act', $E => 'Odd Interest', $F => 'Odd Stranger'];
$tags = [$A => ['hardcore', 'metallic hardcore', 'beatdown hardcore'], $B => ['pop', 'indie pop'],
    $C => ['beatdown hardcore', 'metallic hardcore', 'hardcore'], $D => ['pop', 'synthpop', 'indie pop'],
    $E => ['classical', 'orchestral', 'baroque'], $F => ['classical', 'orchestral', 'baroque']];
$group = static fn (string $id, string $title, string $type, string $date, string $artist) => [
    'id' => $id, 'title' => $title, 'primary-type' => $type, 'first-release-date' => $date, 'secondary-types' => [],
    'artist-credit' => [['name' => $names[$artist], 'artist' => ['id' => $artist, 'name' => $names[$artist]]]], 'tags' => [],
];
$world = [
    'groups' => [
        $group($uuid('11111111', 1), 'Heavy Days', 'Album', $day(2), $C),
        $group($uuid('11111111', 2), 'Shiny', 'EP', $day(3), $D),
        $group($uuid('11111111', 3), 'Followed Album', 'Album', $day(5), $A),
    ],
    // An older album of the followed band gets an expanded edition (5 more tracks) two days ago.
    'releases' => [
        ['title' => 'Old Record…Expanded', 'date' => $day(2), 'track-count' => 15, 'release-group' => ['id' => $uuid('11111111', 9), 'title' => 'Old Record', 'primary-type' => 'Album'],
            'artist-credit' => [['name' => $names[$A], 'artist' => ['id' => $A, 'name' => $names[$A]]]]],
        ['title' => 'Old Record', 'date' => '2019-03-01', 'track-count' => 10, 'release-group' => ['id' => $uuid('11111111', 9), 'title' => 'Old Record', 'primary-type' => 'Album'],
            'artist-credit' => [['name' => $names[$A], 'artist' => ['id' => $A, 'name' => $names[$A]]]]],
    ],
    'down' => false,
    'lastfm_down' => false,
    'delay' => 0,
    'requests' => 0,
    'similar_calls' => 0,
];
// Last.fm Similar Artists, by MusicBrainz id: the unknown hardcore band and the
// band you follow list each other; the rest have no list.
$similarOf = [$C => [['name' => 'Knock Band', 'mbid' => $A, 'match' => '0.8']], $A => [['name' => 'New Hardcore Band', 'mbid' => $C, 'match' => '0.7']]];

$mbTransport = static function (string $url) use (&$world, $names, $tags): array {
    $world['requests']++;
    if ($world['delay'] > 0) {
        usleep($world['delay']);
    }
    if ($world['down']) {
        return [503, '', ''];
    }
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $q);
    $path = substr($parts['path'], strlen('/ws/2/'));
    $json = static fn ($d) => [200, json_encode($d), ''];
    if ($path === 'release-group' && isset($q['query'])) {
        preg_match('/firstreleasedate:\[(\S+) TO (\S+)\]/', $q['query'], $m);
        preg_match('/primarytype:(\w+)/', $q['query'], $t);
        $hits = array_values(array_filter($world['groups'], static fn ($g) => $g['first-release-date'] >= $m[1] && $g['first-release-date'] <= $m[2]
            && strcasecmp($g['primary-type'], $t[1] ?? '') === 0));
        return $json(['count' => count($hits), 'release-groups' => array_slice($hits, (int) ($q['offset'] ?? 0), 100)]);
    }
    if ($path === 'release' && isset($q['query'])) {
        // The day's releases, or the earlier releases of some albums (rgid:(...)).
        $earlier = preg_match('/^date:\[0000 TO (\S+)\] AND rgid:\((.*)\)$/', $q['query'], $m);
        $hits = array_values(array_filter($world['releases'], static fn ($r) => $earlier
            ? $r['date'] <= $m[1] && str_contains($m[2], $r['release-group']['id'])
            : str_contains($q['query'], "date:{$r['date']} ") && stripos($q['query'], "primarytype:{$r['release-group']['primary-type']}") !== false));
        return $json(['count' => count($hits), 'releases' => $hits]);
    }
    if ($path === 'release-group' && isset($q['artist'])) {
        // Like MusicBrainz: every release group the artist is credited on, at any position.
        $hits = array_values(array_filter($world['groups'], static fn ($g) => in_array($q['artist'], array_map(static fn ($c) => $c['artist']['id'], $g['artist-credit']), true)));
        return $json(['release-group-count' => count($hits), 'release-groups' => $hits]);
    }
    if (str_starts_with($path, 'release-group/')) {
        return $json(['releases' => []]);
    }
    if ($path === 'artist' && isset($q['query'])) {
        preg_match_all('/[0-9a-f-]{36}/', $q['query'], $ids);
        return $json(['artists' => array_map(static fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'X', 'country' => 'US',
            'tags' => array_map(static fn ($t) => ['name' => $t, 'count' => 1], $tags[$id] ?? [])], array_values(array_intersect($ids[0], array_keys($names))))]);
    }
    return [404, '{}', ''];
};
$lfmTransport = static function (string $url) use (&$world, $names, $tags, $similarOf): array {
    if ($world['down'] || $world['lastfm_down']) {
        return [503, null, 'down'];
    }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    if (strtolower($q['method']) === 'artist.getsimilar') {
        $world['similar_calls']++;
        $id = $q['mbid'] ?? array_search($q['artist'] ?? '', $names, true);
        return isset($names[$id]) ? [200, json_encode(['similarartists' => ['artist' => $similarOf[$id] ?? [], '@attr' => ['artist' => $names[$id]]]]), '']
            : [200, json_encode(['error' => 6, 'message' => 'The artist you supplied could not be found']), ''];
    }
    $artist = array_search($q['artist'] ?? '', $names, true);
    $json = static fn ($d) => [200, json_encode($d), ''];
    return match (strtolower($q['method'])) {
        'artist.getinfo' => $artist ? $json(['artist' => ['name' => $q['artist'], 'mbid' => $artist, 'bio' => ['summary' => ''],
            'tags' => ['tag' => array_map(static fn ($t) => ['name' => $t], $tags[$artist])]]]) : $json(['error' => 6, 'message' => 'not found']),
        'artist.gettoptags' => $json(['toptags' => ['tag' => array_map(static fn ($t) => ['name' => $t, 'count' => 100], $tags[$artist] ?? [])]]),
        default => $json(['error' => 6, 'message' => 'Album not found']),
    };
};

$profile = [
    'genres' => [['name' => 'hardcore', 'weight' => 0.2], ['name' => 'beatdown hardcore', 'weight' => 0.06], ['name' => 'metallic hardcore', 'weight' => 0.04],
        ['name' => 'pop', 'weight' => 0.1], ['name' => 'indie pop', 'weight' => 0.05], ['name' => 'synthpop', 'weight' => 0.03]],
    'families' => [['name' => 'Hardcore', 'weight' => 0.4], ['name' => 'Pop', 'weight' => 0.3]],
    'attributes' => ['danish_share' => 0.0],
    'artists' => [
        ['name' => 'Knock Band', 'musicbrainz' => ['mbid' => $A], 'watch' => ['level' => 'following'],
            'lastfm' => ['listening' => 'core', 'periods' => ['overall' => ['plays' => 900]]],
            'genres' => ['weights' => ['hardcore' => 0.5, 'beatdown hardcore' => 0.5], 'families' => ['Hardcore' => 1.0]]],
        ['name' => 'Pop Person', 'musicbrainz' => ['mbid' => $B], 'watch' => ['level' => 'interested'],
            'lastfm' => ['listening' => 'current', 'periods' => ['overall' => ['plays' => 300]]],
            'genres' => ['weights' => ['pop' => 0.6, 'indie pop' => 0.4], 'families' => ['Pop' => 1.0]]],
        ['name' => 'Odd Interest', 'musicbrainz' => ['mbid' => $E], 'watch' => ['level' => 'interested'],
            'lastfm' => ['listening' => 'dormant', 'periods' => ['overall' => ['plays' => 50]]],
            'genres' => ['weights' => [], 'families' => []]],
    ],
];

$discCfg = require __DIR__ . '/../config/discovery.php';
$discCfg['editions_from'] = '2000-01-01'; // the simulated world is older than the live setting
$recCfg = require __DIR__ . '/../config/recommender.php';
$lfmKey = str_repeat('ab', 16);
$runner = static fn (?PDO $db = null, ?string $algorithm = null) => new ImportRunner($db ?? $pdo, $discCfg, $recCfg,
    static fn () => new MusicBrainzClient(null, 0, 15, 1, $mbTransport),
    static fn () => new LastFmClient($lfmKey, 10, $lfmTransport),
    [$lfmKey, 'db-secret-password'], algorithm: $algorithm);
$untilDone = static function (int $budget = 20, int $max = 30) use ($runner, $today): array {
    $calls = [];
    for ($i = 0; $i < $max; $i++) {
        $calls[] = $r = $runner()->step($budget, $today);
        if ($r['done'] || $r['status'] === 'error') {
            break;
        }
    }
    return $calls;
};
$feeds = new RecommendationStore($pdo);
$ids = static fn (?array $feed) => array_column($feed['releases'] ?? [], 'id');
$age = static fn () => $pdo->exec("UPDATE slices SET collected_at = '2026-01-01T00:00:00+01:00'") + $pdo->exec("UPDATE meta SET v = '2026-01-01T00:00:00+01:00' WHERE k LIKE 'known_checked%'");

echo "Fallbacks: the site keeps working whatever happens to the import\n";
$fileFeed = new FeedFile(__DIR__ . '/../data/feed.json');
$fromFile = $fileFeed->load();
$viaDb = static fn (Closure $store) => (new CurrentFeed($store, $fileFeed))->load();
$r = $viaDb(static fn () => new RecommendationStore($pdo));
check('before the migration (no recommendation tables): the current feed.json is served', $r['source'] === 'file' && $r['feed'] === $fromFile);
$r = $viaDb(static fn () => throw new PDOException('SQLSTATE[HY000] [2002] Connection refused'));
check('database unreachable: feed.json is served', $r['source'] === 'file' && $r['feed'] === $fromFile);
$snapshot = (new CurrentFeed(static fn () => throw new PDOException('down'), $fileFeed))->release($fromFile['releases'][0]['id']);
check('...and feedback still gets its release snapshot from feed.json', ($snapshot['id'] ?? null) === $fromFile['releases'][0]['id']);

echo "Starting from an empty database\n";
$r = $runner()->step(20, $today);
check('without a music profile: clear error, nothing published', $r['status'] === 'error' && str_contains($r['error'], 'No music profile'), json_encode($r));
$store = new DiscoveryStore($pdo);
$store->saveProfile($profile, 'test', date(DATE_ATOM));

echo "Daily import\n";
$calls = $untilDone();
$last = end($calls);
check('runs until done', $last['done'] === true, json_encode($last));
check('publishes recommendations', ($feedInfo = array_values(array_filter(array_column($calls, 'feed')))) !== [] && $feedInfo[0]['published'], json_encode(array_column($calls, 'feed')));
$active = $feeds->activeFeed();
check('the new releases are in the feed', in_array($uuid('11111111', 1), $ids($active), true), json_encode($ids($active)));
$followed = array_values(array_filter($active['releases'], static fn ($x) => $x['id'] === $uuid('11111111', 3)))[0] ?? null;
check('followed artist\'s release is included and marked', ($followed['origin'] ?? null) === 'following');
$edition = array_values(array_filter($active['releases'], static fn ($x) => $x['id'] === $uuid('11111111', 9)))[0] ?? null;
check('an expanded edition of an older album is new music on its own date', ($edition['title'] ?? null) === 'Old Record…Expanded'
    && ($edition['origin'] ?? null) === 'following', json_encode($edition));
check('only albums and EPs', array_diff(array_unique(array_column($active['releases'], 'type')), ['Album', 'EP']) === []);
$before = $world['requests'];
$again = $runner()->step(20, $today);
check('a second call the same day: nothing due, nothing republished', $again['done'] && $again['feed'] === null && $world['requests'] === $before, json_encode($again));
check('the API serves the active run (same contract)', (new CurrentFeed(static fn () => $feeds, new FeedFile('/nonexistent')))->load()['feed'] === $active);
// The score shown on the site is the engine's own fit, unchanged.
$checkFeedback = FeedbackSet::fromRepository(new FeedbackRepository($pdo))->forEngine();
$checkEngine = new RecommendationEngine(TasteModel::fromProfile($profile, $recCfg), $recCfg, $checkFeedback);
// The same listening values as the import (plays are part of the score).
$checkInterest = new ArtistInterest($profile, $store->listening(), $recCfg['interest'],
    array_values(array_filter($checkFeedback['ratings'], static fn ($r) => !($r['not_interested'] ?? false))));
$checkPipe = new DiscoveryPipeline($store, new MusicBrainzClient(null, 0, 15, 1, $mbTransport), new LastFmClient($lfmKey, 10, $lfmTransport), $checkEngine, $profile, $discCfg,
    null, $checkInterest);
$recalc = $checkPipe->buildFeed($today);
$fits = array_column([...$recalc['recommended'], ...$recalc['wildcards']], 'fit', 'id');
check('every pick carries a score', array_filter($active['releases'], static fn ($x) => !is_float($x['score'] ?? null)) === []);
check('...equal to the fit the engine calculates (no new score)', array_column($active['releases'], 'score', 'id') == $fits, json_encode([array_column($active['releases'], 'score', 'id'), $fits]));
check('every score comes with its parts, and they add up to it', array_filter($active['releases'], static fn ($x) => !is_array($x['scoreParts'] ?? null)
    || abs(array_sum($x['scoreParts']) - $x['score']) > 0.001) === [], json_encode(array_map(static fn ($x) => [$x['score'], $x['scoreParts'] ?? null], $active['releases'])));
check('...with the engine\'s signal names', array_keys($active['releases'][0]['scoreParts']) === ['genre', 'lastfm', 'danish', 'known', 'outside', 'rating']);

echo "A new algorithm version\n";
$runId = $feeds->activeRun()['id'];
$feedbackBefore = (new FeedbackRepository($pdo))->all();
$newCode = $runner(null, 'new-code')->step(20, $today);
check('a deploy with new recommendation code recalculates the same day', ($newCode['feed']['reason'] ?? null) === 'new algorithm version' && $feeds->activeRun()['id'] > $runId,
    json_encode($newCode['feed']));
check('...from the stored data: no MusicBrainz or Last.fm calls, feedback untouched', $world['requests'] === $before && (new FeedbackRepository($pdo))->all() === $feedbackBefore);
$runId = $feeds->activeRun()['id'];
$same = $runner(null, 'new-code')->step(20, $today);
check('...and only once per version', $same['done'] && $same['feed'] === null && $feeds->activeRun()['id'] === $runId, json_encode($same));
check('the version is computed from the deployed code, the same every time', preg_match('/^[0-9a-f]{12}$/', ImportRunner::algorithmVersion()) === 1
    && ImportRunner::algorithmVersion() === ImportRunner::algorithmVersion());
$runner()->step(20, $today); // back to the real version for the rest of the tests

echo "Late registrations and corrections\n";
$world['groups'][] = $group($uuid('11111111', 4), 'Late Entry', 'EP', $day(4), $C);
$runId = $feeds->activeRun()['id'];
$noAge = $runner()->step(20, $today);
check('not re-collected before it is due (data is reused)', !in_array($uuid('11111111', 4), $ids($feeds->activeFeed()), true));
$age();
$calls = $untilDone();
check('a release registered late on MusicBrainz is found on the next re-check', in_array($uuid('11111111', 4), $ids($feeds->activeFeed()), true));
check('...and the feed is replaced by a new run', $feeds->activeRun()['id'] > $runId);
$world['groups'][1]['first-release-date'] = $day(1); // MusicBrainz corrects a date
$age();
$untilDone();
check('a corrected release date is updated', $store->release($uuid('11111111', 2))['first_release_date'] === $day(1));
check('no duplicates after re-collecting', (int) $pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn() === 5); // 4 albums/EPs + the expanded edition

echo "Feedback in the next calculation\n";
$fb = new FeedbackRepository($pdo);
$snap = static fn (string $id) => array_values(array_filter($feeds->activeFeed()['releases'], static fn ($x) => $x['id'] === $id))[0];
$fb->apply($uuid('11111111', 1), FeedbackChange::fromInput(['rating' => 5]), $snap($uuid('11111111', 1)));
$runId = $feeds->activeRun()['id'];
$calls = $untilDone();
$feedRow = array_values(array_filter(array_column($calls, 'feed')))[0] ?? [];
check('a new rating triggers a recalculation', $feeds->activeRun()['id'] > $runId && ($feedRow['reason'] ?? '') === 'your feedback changed', json_encode($feedRow));
$late = array_values(array_filter($feeds->activeFeed()['releases'], static fn ($x) => $x['id'] === $uuid('11111111', 4)))[0] ?? null;
check('the rating is used: the similar release says so', $late !== null && str_contains(json_encode($late['reasons']), 'which you rated 5 stars')
    || str_contains(json_encode($late['reasons'] ?? []), 'You rated Heavy Days by New Hardcore Band 5 stars'), json_encode($late['reasons'] ?? null));
$fb->apply($uuid('11111111', 2), FeedbackChange::fromInput(['notInterested' => true]), $snap($uuid('11111111', 2)));
$untilDone();
check('Not interested hides the release in the next run', !in_array($uuid('11111111', 2), $ids($feeds->activeFeed()), true));
$beforeSave = $feeds->activeFeed()['releases'];
$fb->apply($uuid('11111111', 3), FeedbackChange::fromInput(['saved' => true]), $snap($uuid('11111111', 3)));
$untilDone();
check('Save for later changes nothing in the recommendations', $feeds->activeFeed()['releases'] === $beforeSave);
check('stored ratings survive every import', $fb->get($uuid('11111111', 1))['rating'] === 5 && count($fb->all()) === 3);

echo "Last.fm Similar Artists\n";
$simRows = $store->similar([SimilarArtists::key($A, 'Knock Band'), SimilarArtists::key($C, 'New Hardcore Band'), SimilarArtists::key($B, 'Pop Person')]);
check('collected for your profile artists (whole lists) and the window\'s artists', ($simRows['m:' . $A]['role'] ?? null) === 'profile' && ($simRows['m:' . $C]['role'] ?? null) === 'release',
    json_encode(array_map(static fn ($r) => [$r['role'], $r['identity'], count($r['links'])], $simRows)));
check('...identified by MusicBrainz id', ($simRows['m:' . $C]['identity'] ?? null) === 'mbid');
check('...an artist Last.fm does not know is stored too (no list, not asked again tomorrow)', isset($simRows['m:' . $B]) && $simRows['m:' . $B]['links'] === []);
$heavy = array_values(array_filter($feeds->activeFeed()['releases'], static fn ($x) => $x['id'] === $uuid('11111111', 1)))[0] ?? [];
check('the link reaches the score (both directions) and Last.fm is credited', ($heavy['scoreParts']['lastfm'] ?? 0) > 0 && in_array('Last.fm', $heavy['sources'] ?? [], true),
    json_encode([$heavy['scoreParts'] ?? null, $heavy['sources'] ?? null]));
check('...and the reason says what it is (Last.fm, not "sounds like")', str_contains(json_encode($heavy['reasons'] ?? []), 'Last.fm Similar Artists connects it to Knock Band'),
    json_encode(array_column($heavy['reasons'] ?? [], 'text')));
$before = $world['similar_calls'];
$age();
$untilDone();
check('not fetched again before it is due (cached)', $world['similar_calls'] === $before, (string) ($world['similar_calls'] - $before));
$store->setMeta('similar_done', '0');
$pdo->exec("UPDATE artist_similar SET fetched_at = '2000-01-01T00:00:00+00:00'");
$world['lastfm_down'] = true;
$goodRun = $feeds->activeRun()['id'];
$calls = $untilDone();
check('Last.fm down: the stage pauses, the import still finishes', end($calls)['done'] === true && ($calls[0]['stages']['similar']['backoff'] ?? false) === true,
    json_encode(array_column(array_column($calls, 'stages'), 'similar')));
check('...and the recommendations keep their Last.fm data', ($feeds->activeFeed()['releases'][0]['id'] ?? null) !== null
    && (array_values(array_filter($feeds->activeFeed()['releases'], static fn ($x) => $x['id'] === $uuid('11111111', 1)))[0]['scoreParts']['lastfm'] ?? 0) > 0);
$world['lastfm_down'] = false;
$store->setMeta('similar_backoff_until', '2000-01-01T00:00:00+00:00');

echo "Failures never break the active feed\n";
$good = $feeds->activeFeed();
$goodRun = $feeds->activeRun()['id'];
$world['down'] = true;
$age();
$r = $runner()->step(20, $today);
check('MusicBrainz/Last.fm down: the call reports errors, does not finish', !$r['done'] && $r['status'] !== 'done', json_encode($r));
check('...the active feed is untouched', $feeds->activeFeed() === $good && $feeds->activeRun()['id'] === $goodRun);
check('...and the failures are recorded for retry', count($store->failures()) > 0);
$world['down'] = false;
$calls = $untilDone();
check('when the services are back, the import completes', end($calls)['done'] === true);
$throws = static function (callable $fn): bool {
    try {
        $fn();
    } catch (Throwable) {
        return true;
    }
    return false;
};
$item = $good['releases'][0];
check('a malformed calculation is refused before anything is written',
    $throws(static fn () => $feeds->publish(['releases' => [['id' => 'broken']]] + $good, 'test', [], date(DATE_ATOM))) && $feeds->activeFeed() === $good);
$bad = $good;
$bad['releases'][] = ['title' => "\xB1\x31 not utf-8"] + $item; // fails halfway through storing
check('a calculation that fails while being stored is never activated (run marked failed)',
    $throws(static fn () => $feeds->publish($bad, 'test', [], date(DATE_ATOM))) && $feeds->runs(1)[0]['status'] === 'failed' && $feeds->activeFeed() === $good);
$r = $viaDb(static fn () => throw new RuntimeException('feed source switched to file'));
check('emergency switch (feed source = file): feed.json is served even with an active run', $r['source'] === 'file' && $r['feed'] === $fromFile);
check('feedback is independent of the import tables', (new FeedbackRepository($pdo))->get($uuid('11111111', 1))['rating'] === 5);
$previous = array_values(array_filter($feeds->runs(10), static fn ($r) => $r['status'] === 'superseded'))[0]['id'] ?? null;
$feeds->activate((int) $previous);
check('an earlier working run can be re-activated', (int) $feeds->activeRun()['id'] === (int) $previous);

echo "Short runs, resuming, locking, time limit\n";
$world['delay'] = 400_000; // 0.4 s per MusicBrainz answer
// This client caches nothing, so a day is re-read from scratch after a stop; the
// server's client re-reads it from its cache. Keep every day within one short call.
$world['releases'] = [];
$age();
$r1 = $runner()->step(2, $today);
check('a short budget stops early and leaves work for the next call', !$r1['done'], json_encode($r1));
check('...and stays within the budget (+ one request)', $r1['seconds'] <= 2 + 1.5, (string) $r1['seconds']);
$calls = $untilDone(2, 60);
check('repeated short calls finish the import (resume)', end($calls)['done'] === true, count($calls) . ' calls');
check('every call within the budget', max(array_column($calls, 'seconds')) <= 4.0, json_encode(array_column($calls, 'seconds')));
$world['delay'] = 0;
$other = Database::connect($dbCfg);
$other->query("SELECT GET_LOCK(CONCAT(DATABASE(), ':music_radar_import'), 0)")->fetchColumn();
check('a second import at the same time is refused (busy)', $runner()->step(20, $today)['status'] === 'busy');
$other->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':music_radar_import'))")->fetchColumn();
check('...and can run once the first is finished', $runner()->step(20, $today)['status'] !== 'busy');

echo "Last.fm for known artists, whatever the preliminary score\n";
$world['groups'][] = $group($uuid('11111111', 7), 'Strange Interest', 'Album', $day(3), $E);
$world['groups'][] = $group($uuid('11111111', 8), 'Strange Stranger', 'Album', $day(3), $F);
$age();
$untilDone();
$known = $store->release($uuid('11111111', 7));
$stranger = $store->release($uuid('11111111', 8));
$min = (float) $discCfg['lastfm_min_prescore'];
check('a known (Interested) artist\'s release has a low preliminary score', $known !== null && (float) $known['prescore'] < $min, json_encode([$known['prescore'] ?? null, $min]));
check('...and is still looked up on Last.fm (album + artist)', $known['lastfm_album_status'] !== null && $store->artist($E)['lastfm_fetched_at'] !== null,
    json_encode([$known['lastfm_album_status'], $store->artist($E)['lastfm_status'] ?? null]));
$knownItem = array_values(array_filter($feeds->activeFeed()['releases'], static fn ($x) => $x['id'] === $uuid('11111111', 7)))[0] ?? null;
check('...so its Last.fm data reaches the recommendations (source shown)', in_array('Last.fm', $knownItem['sources'] ?? [], true), json_encode($knownItem['sources'] ?? null));
check('an unknown artist with the same low score is still skipped (the gate works)', (float) $stranger['prescore'] < $min
    && $stranger['prescore_quality'] !== 'insufficient' && $stranger['lastfm_album_status'] === null,
    json_encode([$stranger['prescore'], $stranger['prescore_quality'], $stranger['lastfm_album_status']]));

echo "Optional TIDAL enrichment never blocks an active feed\n";
$activeBeforeLinks = $feeds->activeRun()['id'];
$linkCalls = 0;
$withLinks = new ImportRunner($pdo, $discCfg, $recCfg,
    static fn () => new MusicBrainzClient(null, 0, 15, 1, $mbTransport),
    static fn () => new LastFmClient($lfmKey, 10, $lfmTransport),
    tidal: static function (string $now) use (&$linkCalls): array {
        $linkCalls++;
        return ['pending' => $linkCalls === 1, 'checked' => 1];
    });
$step = $withLinks->step(20, $today);
check('optional backfill continues across calls after the feed is ready', !$step['done'] && $step['tidal']['pending']);
check('the existing recommendation run remains active during backfill', $feeds->activeRun()['id'] === $activeBeforeLinks);
check('optional work can finish without recalculating music', $withLinks->step(20, $today)['done'] && $feeds->activeRun()['id'] === $activeBeforeLinks);
$brokenLinks = new ImportRunner($pdo, $discCfg, $recCfg,
    static fn () => new MusicBrainzClient(null, 0, 15, 1, $mbTransport),
    static fn () => new LastFmClient($lfmKey, 10, $lfmTransport),
    tidal: static fn () => throw new RuntimeException('optional service unavailable'));
$step = $brokenLinks->step(20, $today);
check('optional failure leaves the import successful and the feed untouched', $step['done'] && $step['errors'] === 0
    && $step['tidal']['status'] === 'error' && $step['tidal']['deferred'] && $feeds->activeRun()['id'] === $activeBeforeLinks);

$remainingBudget = null;
$budgetedLinks = new ImportRunner($pdo, $discCfg, $recCfg,
    static fn () => new MusicBrainzClient(null, 0, 15, 1, $mbTransport),
    static fn () => new LastFmClient($lfmKey, 10, $lfmTransport),
    tidal: static function (string $now, float $budget) use (&$remainingBudget): array {
        $remainingBudget = $budget;
        return ['pending' => true, 'checked' => 0];
    });
$step = $budgetedLinks->step(2, $today);
check('optional queue registration runs even when fewer than seven seconds remain', $remainingBudget !== null && $remainingBudget >= 0 && $remainingBudget <= 2);
check('deferred link work stays resumable without delaying the published feed', !$step['done'] && $step['tidal']['checked'] === 0
    && $feeds->activeRun()['id'] === $activeBeforeLinks);

echo "Safety\n";
$beforeTidalOnly = ['data' => $store->fingerprints(), 'feed' => $feeds->activeFeed(),
    'feedback' => $pdo->query('SELECT * FROM release_feedback ORDER BY release_id')->fetchAll(),
    'log' => $pdo->query('SELECT COUNT(*) FROM import_log')->fetchColumn()];
$onlyCalls = 0;
$tidalOnly = new ImportRunner($pdo, $discCfg, $recCfg,
    static fn () => throw new RuntimeException('music collection must not start'),
    static fn () => throw new RuntimeException('Last.fm enrichment must not start'),
    tidal: static function (string $now, float $budget) use (&$onlyCalls): array {
        $onlyCalls++;
        return ['pending' => $onlyCalls === 1, 'checked' => 1];
    });
$r = $tidalOnly->stepTidal(20);
check('a standalone TIDAL step resumes without starting music collection', $r['mode'] === 'tidal_only' && !$r['done'] && $onlyCalls === 1);
check('the standalone TIDAL job can complete without calculating recommendations', $tidalOnly->stepTidal(20)['done'] && $onlyCalls === 2);
check('standalone TIDAL leaves music data, recommendations, feedback and import history untouched', $beforeTidalOnly === [
    'data' => $store->fingerprints(), 'feed' => $feeds->activeFeed(),
    'feedback' => $pdo->query('SELECT * FROM release_feedback ORDER BY release_id')->fetchAll(),
    'log' => $pdo->query('SELECT COUNT(*) FROM import_log')->fetchColumn()]);
$other->query("SELECT GET_LOCK(CONCAT(DATABASE(), ':music_radar_import'), 0)")->fetchColumn();
check('standalone TIDAL respects the ordinary import lock', $tidalOnly->stepTidal(20)['status'] === 'busy' && $onlyCalls === 2);
$other->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':music_radar_import'))")->fetchColumn();
check('standalone TIDAL resumes after the ordinary import releases its lock', $tidalOnly->stepTidal(20)['done'] && $onlyCalls === 3);
$log = $pdo->query('SELECT GROUP_CONCAT(COALESCE(error, "") SEPARATOR " ") FROM import_log')->fetchColumn() . json_encode($store->failures());
check('no secrets in the import log or failures', !str_contains((string) $log, $lfmKey) && !str_contains((string) $log, 'db-secret-password'));
check('secrets are redacted from messages', $runner()->redact("x api_key=$lfmKey&y password=hunter2") === 'x api_key=[redacted]&y password=[redacted]');
$src = implode("\n", array_map('file_get_contents', glob(__DIR__ . '/../src/*/*.php')));
check('no code path drops or truncates tables (deploys/imports never wipe data)', !preg_match('/DROP\s+TABLE|TRUNCATE/i', $src));

foreach (['releases', 'artists', 'slices', 'failures', 'meta', 'profiles', 'recommendation_runs', 'recommendation_items', 'import_log', 'release_feedback', 'release_feedback_log', 'artist_similar'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}
echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
