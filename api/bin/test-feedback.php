<?php

declare(strict_types=1);

/**
 * Integration tests for stored feedback (ratings, Not interested, Save for
 * later): validation, the API controller, the database repository and the
 * bridge into the recommendation engine.
 *
 * Runs against a SEPARATE test database (default music_radar_test, tables are
 * dropped and recreated) - never the database the site uses.
 *
 *   docker compose exec php php /var/www/api/bin/test-feedback.php
 *
 * One-time setup of the local test database:
 *   docker compose exec db mariadb -uroot -proot -e "CREATE DATABASE music_radar_test;
 *     GRANT ALL ON music_radar_test.* TO 'music_radar'@'%'"
 */

use MusicRadar\Controllers\FeedbackController;
use MusicRadar\Database\Database;
use MusicRadar\Feed\CurrentFeed;
use MusicRadar\Feed\FeedFile;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Feedback\FeedbackChange;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Feedback\FeedbackSet;
use MusicRadar\Feedback\Schema;
use MusicRadar\Http\Request;
use MusicRadar\Recommend\RecommendationEngine;
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
function throws(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

$connect = static fn () => Database::connect($dbCfg);
$pdo = $connect();
$pdo->exec('DROP TABLE IF EXISTS release_feedback, release_feedback_log');
echo "Setup on first use (as on the server)\n";
Schema::ensure($pdo);
$tables = static fn () => $pdo->query("SHOW TABLES LIKE 'release_feedback%'")->fetchAll(PDO::FETCH_COLUMN);
check('missing tables are created on first use', $tables() === ['release_feedback', 'release_feedback_log'], json_encode($tables()));
$pdo->exec("INSERT INTO release_feedback_log (release_id, field, created_at) VALUES ('00000000-0000-0000-0000-000000000009', 'saved', NOW())");
Schema::ensure($pdo);
check('...and later calls leave existing tables and data alone', (int) $pdo->query('SELECT COUNT(*) FROM release_feedback_log')->fetchColumn() === 1);
$pdo->exec('DELETE FROM release_feedback_log');

// A small feed file, like data/feed.json
$id = static fn (int $n) => sprintf('00000000-0000-0000-0000-%012d', $n);
$feedRelease = static fn (int $n, array $tags, string $artistMbid) => [
    'id' => $id($n), 'artist' => "Artist $n", 'artistMbid' => $artistMbid, 'title' => "Title $n", 'type' => 'Album',
    'releaseDate' => '2026-10-20', 'genre' => 'Hardcore', 'tags' => $tags, 'country' => null, 'origin' => 'discovery',
    'dataQuality' => 'good', 'pick' => ['kind' => 'recommended', 'angle' => null, 'rank' => $n], 'reasons' => [], 'sources' => ['MusicBrainz'],
];
$feedPath = tempnam(sys_get_temp_dir(), 'feed');
$writeFeed = static fn (array $releases) => file_put_contents($feedPath, json_encode(['releases' => $releases]));
$writeFeed([
    $feedRelease(1, ['beatdown hardcore', 'metallic hardcore', 'hardcore'], 'aaaaaaaa-0000-0000-0000-000000000001'),
    $feedRelease(2, ['indie pop', 'pop'], 'aaaaaaaa-0000-0000-0000-000000000002'),
    $feedRelease(3, ['hardcore punk'], 'aaaaaaaa-0000-0000-0000-000000000003'),
]);
$feed = new FeedFile($feedPath);
$repo = new FeedbackRepository($pdo);

echo "Validation\n";
check('rating 1-5 accepted', FeedbackChange::fromInput(['rating' => 5])->value === 5 && FeedbackChange::fromInput(['rating' => 1])->value === 1);
check('rating null = remove', FeedbackChange::fromInput(['rating' => null])->value === null);
foreach ([['rating' => 0], ['rating' => 6], ['rating' => '5'], ['rating' => 4.5], ['saved' => 'yes'], ['notInterested' => 1], [], ['rating' => 5, 'saved' => true], ['user_id' => 2], 'rating', [5]] as $bad) {
    check('rejected: ' . json_encode($bad), throws(static fn () => FeedbackChange::fromInput($bad), InvalidArgumentException::class));
}
check('release id must be a MusicBrainz id', FeedbackChange::validReleaseId($id(1)) && !FeedbackChange::validReleaseId("1' OR '1'='1") && !FeedbackChange::validReleaseId(strtoupper($id(1)) . 'x'));

echo "Ratings are stored, changed and removed\n";
$r = $repo->apply($id(1), FeedbackChange::fromInput(['rating' => 5]), $feed->release($id(1)));
check('rating saved', $r['rating'] === 5 && $r['release']['title'] === 'Title 1');
check('survives a new connection (reload, other browser)', (new FeedbackRepository($connect()))->get($id(1))['rating'] === 5);
$repo->apply($id(1), FeedbackChange::fromInput(['rating' => 3]), $feed->release($id(1)));
check('rating changed', $repo->get($id(1))['rating'] === 3);
$repo->apply($id(1), FeedbackChange::fromInput(['rating' => null]), $feed->release($id(1)));
check('rating removed (row gone)', $repo->get($id(1)) === null);
check('...but the history keeps every step', array_column($repo->history($id(1)), 'new') === ['5', '3', null], json_encode($repo->history($id(1))));
check('unknown release (not in the feed, never marked) is refused', throws(static fn () => $repo->apply($id(99), FeedbackChange::fromInput(['rating' => 5]), null), DomainException::class));

echo "Save for later and Not interested\n";
$repo->apply($id(2), FeedbackChange::fromInput(['saved' => true]), $feed->release($id(2)));
check('saved', $repo->get($id(2))['saved'] === true);
$repo->apply($id(2), FeedbackChange::fromInput(['saved' => false]), $feed->release($id(2)));
check('bookmark removed', $repo->get($id(2)) === null);
$repo->apply($id(3), FeedbackChange::fromInput(['rating' => 4]), $feed->release($id(3)));
$repo->apply($id(3), FeedbackChange::fromInput(['saved' => true]), $feed->release($id(3)));
$repo->apply($id(3), FeedbackChange::fromInput(['notInterested' => true]), $feed->release($id(3)));
$ni = $repo->get($id(3));
check('Not interested clears rating and bookmark (same rules as the UI)', $ni['notInterested'] && $ni['rating'] === null && !$ni['saved']);
$repo->apply($id(3), FeedbackChange::fromInput(['notInterested' => false]), $feed->release($id(3)));
check('Not interested can be undone', $repo->get($id(3)) === null);
$repo->apply($id(3), FeedbackChange::fromInput(['notInterested' => true]), $feed->release($id(3)));
$repo->apply($id(3), FeedbackChange::fromInput(['rating' => 2]), $feed->release($id(3)));
check('rating after Not interested clears it', $repo->get($id(3))['rating'] === 2 && !$repo->get($id(3))['notInterested']);
check('database refuses a rating out of range', throws(static fn () => $pdo->exec("INSERT INTO release_feedback VALUES (1, '{$id(50)}', 9, 0, 0, '{}', NOW(), NOW())"), PDOException::class));
check('database refuses rating + Not interested together', throws(static fn () => $pdo->exec("INSERT INTO release_feedback VALUES (1, '{$id(51)}', 3, 1, 0, '{}', NOW(), NOW())"), PDOException::class));

echo "Feedback outlives the feed\n";
$repo->apply($id(1), FeedbackChange::fromInput(['rating' => 5]), $feed->release($id(1)));
$writeFeed([$feedRelease(2, ['indie pop', 'pop'], 'aaaaaaaa-0000-0000-0000-000000000002')]); // a new export without release 1
$feed2 = new FeedFile($feedPath);
check('a new feed export does not touch stored feedback', $repo->get($id(1))['rating'] === 5 && $feed2->release($id(1)) === null);
$repo->apply($id(1), FeedbackChange::fromInput(['rating' => 4]), $feed2->release($id(1)));
check('a release no longer in the feed can still be re-rated (snapshot kept)', $repo->get($id(1))['rating'] === 4 && $repo->get($id(1))['release']['title'] === 'Title 1');
check('My Music data: all marked releases with their snapshots', count($repo->all()) === 2 && array_column(array_column($repo->all(), 'release'), 'title') !== []);

echo "API controller\n";
$fileOnly = new CurrentFeed(static fn () => throw new RuntimeException("no recommendation store in this test"), $feed2);
$loggedIn = true;
$controller = new FeedbackController(static fn () => $repo, $fileOnly, static function () use (&$loggedIn): bool {
    return $loggedIn;
});
$post = static fn (string $rid, string $body, array $headers = []) => $controller->update(new Request('POST', "/feedback/$rid", [], $body, ['id' => $rid],
    $headers + ['content-type' => 'application/json', 'host' => 'musik.hornpedersen.dk', 'origin' => 'https://musik.hornpedersen.dk']));
check('GET lists items', count($controller->index(new Request('GET', '/feedback'))->data['items']) === 2);
check('POST rating -> 200 with the stored item', ($res = $post($id(2), '{"rating":5}'))->status === 200 && $res->data['rating'] === 5);
check('invalid id -> 400', $post('../etc/passwd', '{"rating":5}')->status === 400);
check('invalid JSON -> 400', $post($id(2), '{rating:5')->status === 400);
check('extra fields -> 400', $post($id(2), '{"rating":5,"saved":true}')->status === 400);
check('rating 6 -> 400 with a clear message', ($res = $post($id(2), '{"rating":6}'))->status === 400 && str_contains($res->data['error'], '1 to 5'));
check('form post (not JSON) -> 415', $post($id(2), 'rating=1', ['content-type' => 'application/x-www-form-urlencoded'])->status === 415);
check('from another website -> 403', $post($id(2), '{"rating":1}', ['origin' => 'https://evil.example'])->status === 403);
check('too large -> 413', $post($id(2), '{"rating":5' . str_repeat(' ', 2000) . '}')->status === 413);
check('unknown release -> 404', $post($id(77), '{"rating":5}')->status === 404);
check('nothing was changed by the rejected requests', $repo->get($id(2))['rating'] === 5);
$loggedIn = false;
$before = $repo->all();
check('not logged in: a rating is refused (401)', ($res = $post($id(2), '{"rating":1}'))->status === 401 && str_contains($res->data['error'], 'Log in'));
check('...and so are Not interested and Save for later', $post($id(2), '{"notInterested":true}')->status === 401 && $post($id(2), '{"saved":true}')->status === 401);
check('...nothing changed', $repo->all() === $before);
check('...while anyone can still read them', count($controller->index(new Request('GET', '/feedback'))->data['items']) === count($repo->all()));
$loggedIn = true;
$broken = new FeedbackController(static fn () => throw new PDOException('SQLSTATE[HY000] [1045] Access denied for user secret@db'), $fileOnly, static fn () => true);
$res = $broken->index(new Request('GET', '/feedback'));
check('database down -> 503 without internal details', $res->status === 503 && !str_contains(json_encode($res->data), 'SQLSTATE') && !str_contains(json_encode($res->data), 'secret'), json_encode($res->data));

echo "My Music shows the current score, not the one from when you rated\n";
// Release 1 was rated when an older model scored it 0.66 and recommended it.
$old = $repo->get($id(1))['release'] + ['score' => 0.66, 'scoreParts' => ['genre' => 0.66]];
$old['pick'] = ['kind' => 'recommended', 'angle' => null, 'rank' => 1];
$pdo->prepare('UPDATE release_feedback SET release_snapshot = ? WHERE release_id = ?')->execute([json_encode($old), $id(1)]);
$pdo->exec('DROP TABLE IF EXISTS recommendation_items, recommendation_scores, recommendation_runs');
$runs = new RecommendationStore($pdo);
$runs->install();
// The current run picks release 3, only assesses release 1, and never saw release 2.
$picked = $feedRelease(3, ['hardcore punk'], 'aaaaaaaa-0000-0000-0000-000000000003') + ['score' => 0.52, 'scoreParts' => ['genre' => 0.52]];
$runs->publish(['generatedAt' => '2026-10-31T00:00:00Z', 'window' => ['2026-10-01', '2026-10-31'], 'examined' => 3, 'taste' => [], 'releases' => [$picked]],
    'test', [], '2026-10-31T00:00:00Z', [$id(1) => ['score' => 0.398, 'scoreParts' => ['genre' => 0.398]], $id(3) => ['score' => 0.52, 'scoreParts' => ['genre' => 0.52]]]);
$withRuns = new FeedbackController(static fn () => $repo, new CurrentFeed(static fn () => $runs, $feed2), static fn () => true);
$repo->apply($id(1), FeedbackChange::fromInput(['rating' => 2]), null);
$marked = array_column($withRuns->index(new Request('GET', '/feedback'))->data['items'], null, 'releaseId');
check('a negatively rated release stays in My Music after leaving Recommended', $marked[$id(1)]['rating'] === 2
    && $marked[$id(1)]['release']['pick']['kind'] === 'none');
$items = array_column($withRuns->index(new Request('GET', '/feedback'))->data['items'], 'release', 'releaseId');
check('assessed but not picked: the current score, not recommended', $items[$id(1)]['score'] === 0.398 && $items[$id(1)]['pick']['kind'] === 'none'
    && $items[$id(1)]['scoreParts'] === ['genre' => 0.398], json_encode($items[$id(1)]));
check('...and its facts still come from the snapshot', $items[$id(1)]['title'] === 'Title 1' && $items[$id(1)]['tags'] === ['beatdown hardcore', 'metallic hardcore', 'hardcore']);
check('picked: the current entry (score, pick)', $items[$id(3)]['score'] === 0.52 && $items[$id(3)]['pick']['kind'] === 'recommended');
check('not assessed by the current run: no score at all', $items[$id(2)]['score'] === null && $items[$id(2)]['scoreParts'] === null && $items[$id(2)]['pick']['kind'] === 'none');
check('the stored snapshot keeps its history', $repo->get($id(1))['release']['score'] === 0.66);
check('a change answers with the current score too', ($res = $withRuns->update(new Request('POST', "/feedback/{$id(1)}", [], '{"rating":5}', ['id' => $id(1)],
    ['content-type' => 'application/json', 'host' => 'h', 'origin' => 'https://h'])))->status === 200 && $res->data['release']['score'] === 0.398 && $res->data['rating'] === 5);
check('only the kept runs keep scores', (int) $pdo->query('SELECT COUNT(*) FROM recommendation_scores')->fetchColumn() === 2);
$withoutRun = new FeedbackController(static fn () => $repo, $fileOnly, static fn () => true);
check('no database run (feed.json only): no old score shown as current',
    $withoutRun->index(new Request('GET', '/feedback'))->data['items'][0]['release']['score'] === null);
$pdo->exec('DROP TABLE IF EXISTS recommendation_items, recommendation_scores, recommendation_runs');
$repo->apply($id(1), FeedbackChange::fromInput(['rating' => 4]), null);

echo "Bridge into the recommendation engine\n";
$set = FeedbackSet::fromRepository($repo);
$engineFeedback = $set->forEngine();
check('ratings go to the engine with tags and artist', count($engineFeedback['ratings']) === 3 && $engineFeedback['ratings'][0]['artist_mbid'] !== null, json_encode($engineFeedback));
$repo->apply($id(2), FeedbackChange::fromInput(['saved' => true]), $feed2->release($id(2)));
$repo->apply($id(2), FeedbackChange::fromInput(['rating' => null]), $feed2->release($id(2)));
$onlySaved = FeedbackSet::fromRepository($repo)->forEngine();
check('Save for later is not passed to the engine', count($onlySaved['ratings']) === 2 && !in_array($id(2), array_column($onlySaved['ratings'], 'release_id'), true));
$ni = FeedbackSet::fromItems([['releaseId' => $id(7), 'rating' => null, 'notInterested' => true, 'saved' => false,
    'release' => ['artistMbid' => 'mbid-x', 'artist' => 'X', 'title' => 'T', 'tags' => ['hardcore']]]])->forEngine();
check('Not interested: hidden, and a 1-star signal for the artist only (no tags)', $ni['not_interested_releases'] === [$id(7)]
    && count($ni['ratings']) === 1 && $ni['ratings'][0]['stars'] === 1 && $ni['ratings'][0]['tags'] === [] && $ni['ratings'][0]['not_interested'] === true, json_encode($ni));
check('the same items work from the API format', FeedbackSet::fromItems(json_decode(json_encode(['items' => $repo->all()]), true)['items'])->forEngine() === $onlySaved);
check('broken items are ignored', FeedbackSet::fromItems([['releaseId' => 'x'], 'junk', ['releaseId' => $id(5), 'release' => 'no']])->forEngine() === ['ratings' => [], 'not_interested_releases' => []]);
check('unknown source is refused (no silent fallback)', throws(static fn () => FeedbackSet::fromSource('ftp://x', $dbCfg), RuntimeException::class));
check('"none" = no feedback', FeedbackSet::fromSource('none', $dbCfg)->forEngine() === ['ratings' => [], 'not_interested_releases' => []]);
$api = FeedbackSet::fromUrl('http://localhost/api/feedback');
check('reads the site\'s GET /api/feedback over HTTP', is_array($api->items));

$recCfg = require __DIR__ . '/../config/recommender.php';
$profile = ['genres' => [['name' => 'hardcore', 'weight' => 0.2], ['name' => 'beatdown hardcore', 'weight' => 0.05]],
    'families' => [['name' => 'Hardcore', 'weight' => 0.4]], 'attributes' => [], 'artists' => []];
$taste = TasteModel::fromProfile($profile, $recCfg);
$candidate = ['id' => $id(60), 'title' => 'New', 'type' => 'Album', 'first_release_date' => '2026-10-20', 'metadata_quality' => 'good',
    'artist' => ['name' => 'Someone', 'mbid' => 'bbbbbbbb-0000-0000-0000-000000000001', 'primary_name' => 'Someone', 'credited_artists' => 1],
    'facts' => [['id' => 'F1', 'text' => 'Artist genres/tags: beatdown hardcore, metallic hardcore, hardcore', 'source' => 'musicbrainz:artist']]];
foreach ([null, 1, 2, 3, 4, 5] as $stars) {
    $item = ['releaseId' => $id(60), 'rating' => $stars, 'notInterested' => false, 'saved' => false,
        'release' => ['artistMbid' => $candidate['artist']['mbid'], 'tags' => ['hardcore']]];
    $known = [$id(60) => ['watch_level' => 'interested'] + $candidate];
    $beforeSave = FeedbackSet::fromItems([$item])->forEngine();
    $item['saved'] = true;
    $afterSave = FeedbackSet::fromItems([$item])->forEngine();
    $result = (new RecommendationEngine($taste, $recCfg, $afterSave))->run($known);
    check('Save for later preserves the rating decision for Interested: ' . ($stars ?? 'unrated'), $beforeSave === $afterSave
        && (in_array($id(60), array_column($result['recommended'], 'id'), true) === ($stars === null || $stars >= 3))
        && $result['wildcards'] === []);
}
$plain = (new RecommendationEngine($taste, $recCfg))->assess($candidate)['fit'];
$learned = (new RecommendationEngine($taste, $recCfg, FeedbackSet::fromRepository($repo)->forEngine()))->assess($candidate)['fit'];
check('a stored 4-star rating of a similar release lifts a new one a little', $learned > $plain && $learned - $plain < 0.1, "$plain -> $learned");
$repo->apply($id(1), FeedbackChange::fromInput(['notInterested' => true]), null);
$ni = new RecommendationEngine($taste, $recCfg, FeedbackSet::fromRepository($repo)->forEngine());
check('Not interested from the database hides that release in the next run', $ni->assess(['id' => $id(1)] + $candidate)['excluded'] === true);
$sameRatingsWithoutNi = new RecommendationEngine($taste, $recCfg, ['ratings' => FeedbackSet::fromRepository($repo)->forEngine()['ratings']]);
check('...and teaches nothing about the music (scores equal without it)', $ni->assess($candidate)['fit'] === $sameRatingsWithoutNi->assess($candidate)['fit']);

$pdo->exec('DROP TABLE IF EXISTS release_feedback, release_feedback_log');
unlink($feedPath);
echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
