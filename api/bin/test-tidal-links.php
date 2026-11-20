<?php

declare(strict_types=1);

use MusicRadar\Database\Database;
use MusicRadar\Controllers\RecommendationsController;
use MusicRadar\Controllers\SearchController;
use MusicRadar\Controllers\FeedbackController;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Feed\CurrentFeed;
use MusicRadar\Feed\FeedFile;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Feedback\FeedbackChange;
use MusicRadar\Feedback\Schema;
use MusicRadar\Http\Request;
use MusicRadar\Links\TidalImport;
use MusicRadar\Links\TidalLinkStore;
use MusicRadar\Links\TidalMatch;
use MusicRadar\MusicBrainz\MusicBrainzClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$app = require __DIR__ . '/../bootstrap.php';
\MusicRadar\Links\TidalLinkStore::$firstCheckDelayHours = 0; // these tests check at once; the delay has its own test
$cfg = ['name' => getenv('TEST_DB_NAME') ?: 'music_radar_test'] + $app->config['db'];
if (!str_ends_with($cfg['name'], '_test')) {
    fwrite(STDERR, "Refusing to run: the test database name must end in _test.\n");
    exit(2);
}
$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

$id = 'aaaaaaaa-0000-0000-0000-000000000001';
$artist = 'bbbbbbbb-0000-0000-0000-000000000001';
$editionId = 'cccccccc-0000-0000-0000-000000000001';
$now = '2026-10-31T12:00:00+00:00';
$row = ['id' => $id, 'title' => 'New Album', 'first_release_date' => '2026-10-30'];
$credits = [['mbid' => $artist]];
$edition = ['id' => $editionId, 'title' => 'New Album', 'date' => '2026-10-30', 'status' => 'Official', 'barcode' => '123456789012',
    'artist-credit' => [['artist' => ['id' => $artist]]], 'media' => [['format' => 'Digital Media', 'track-count' => 8]],
    'relations' => [['type' => 'streaming', 'url' => ['resource' => 'https://tidal.com/album/556197748']]]];
$response = ['release-count' => 1, 'releases' => [$edition]];
$assess = static fn (array $response) => TidalMatch::assess($row, $credits, $response);
$mutate = static function (array $changes) use ($edition): array {
    return ['release-count' => 1, 'releases' => [array_replace($edition, $changes)]];
};
check('a unique matching digital edition is verified without a manual album list', $assess($response)['status'] === 'verified');
check('the automatic match includes the concrete edition and album ID', $assess($response) === ['status' => 'verified', 'album' => '556197748', 'edition' => $editionId]);
check('missing relation is not a search fallback', $assess($mutate(['relations' => []])) === ['status' => 'missing']);
check('a physical copy never supplies a listening link', $assess($mutate(['media' => [['format' => 'CD', 'track-count' => 8]]]))['status'] === 'missing');
check('a bootleg is ignored', $assess($mutate(['status' => 'Bootleg']))['status'] === 'missing');
foreach (['title' => 'New Album (Deluxe)', 'artist-credit' => [['artist' => ['id' => 'someone-else']]],
    'media' => [['format' => 'Digital Media', 'track-count' => 0]]] as $field => $value) {
    check("conflicting $field hides the automatic link", $assess($mutate([$field => $value]))['status'] === 'ambiguous');
}
check('the only TIDAL album is used although its date differs', ($assess($mutate(['date' => '2026-11-02']))['album'] ?? null) === '556197748');
check('...and whatever note it carries about how it is sold', ($assess($mutate(['disambiguation' => 'Streaming Release']))['album'] ?? null) === '556197748');
check('a note meaning other music is the album itself when every edition carries it', ($assess($mutate(['disambiguation' => 'acoustic']))['album'] ?? null) === '556197748');
$cd = array_replace($edition, ['id' => 'cd-edition', 'relations' => [], 'media' => [['format' => 'CD', 'track-count' => 8]]]);
check('...but another version when a normal edition exists (no link to the acoustic version)',
    $assess(['release-count' => 2, 'releases' => [array_replace($edition, ['disambiguation' => 'acoustic']), $cd]])['status'] === 'ambiguous');
check('"live" is a whole word, not part of "delivered"', ($assess(['release-count' => 2, 'releases' => [array_replace($edition, ['disambiguation' => 'delivered digitally']), $cd]])['album'] ?? null) === '556197748');
check('an incomplete page cannot accidentally select one edition', $assess(['release-count' => 101, 'releases' => [$edition]])['status'] === 'ambiguous');
$other = array_replace($edition, ['id' => 'another-edition', 'relations' => [['type' => 'streaming', 'url' => ['resource' => 'https://tidal.com/album/123']]]]);
check('two different album IDs are ambiguous, not first-match wins', $assess(['release-count' => 2, 'releases' => [$edition, $other]])['status'] === 'ambiguous');
$duplicate = array_replace($edition, ['relations' => [['type' => 'streaming', 'url' => ['resource' => 'https://listen.tidal.com/browse/album/556197748']]]]);
check('URL variants of the same album ID are deduplicated', $assess(['release-count' => 2, 'releases' => [$edition, $duplicate]])['status'] === 'verified');
$version = static fn (string $dis, string $album, string $editionId) => array_replace($edition, ['id' => $editionId, 'disambiguation' => $dis,
    'relations' => [['type' => 'streaming', 'url' => ['resource' => "https://tidal.com/album/$album"]]]]);
$pick = static fn (array ...$versions) => $assess(['release-count' => count($versions), 'releases' => $versions]);
check('several versions: the main edition wins over clean and Dolby Atmos', $pick($version('clean', '200', 'e2'), $version('', '100', 'e1'),
    $version('Dolby Atmos mix', '300', 'e3')) === ['status' => 'verified', 'album' => '100', 'edition' => 'e1']);
check('...an explicitly marked explicit edition wins over the main edition', ($pick($version('', '100', 'e1'), $version('explicit', '400', 'e4'))['album'] ?? null) === '400');
check('...clean wins over Dolby Atmos', ($pick($version('Dolby Atmos', '300', 'e3'), $version('clean', '200', 'e2'))['album'] ?? null) === '200');
check('...a single clean edition with a link is enough', ($pick($version('clean', '200', 'e2'))['album'] ?? null) === '200');
check('two albums of the same kind are never guessed', $pick($version('', '100', 'e1'), $version('', '101', 'e5'))['status'] === 'ambiguous');
check('an unknown version (deluxe) among several albums stays ambiguous', $pick($version('', '100', 'e1'), $version('deluxe', '500', 'e6'))['status'] === 'ambiguous');
check('several albums: one titled "(Deluxe)" is another album, the matching title wins', ($pick($version('', '100', 'e1'),
    array_replace($version('', '600', 'e7'), ['title' => 'New Album (Deluxe)']))['album'] ?? null) === '100');
$label = static fn (array $e, string $name, string $country = 'XW') => $e + ['country' => $country, 'label-info' => [['label' => ['name' => $name]]]];
$europe = $label(array_replace($edition, ['id' => 'eu-vinyl', 'relations' => [], 'media' => [['format' => '12" Vinyl', 'track-count' => 8]]]), 'EU Records', 'XE');
check('two unmarked albums from different labels: the label that releases it in Europe wins', ($pick($label($version('', '700', 'us'), 'US Records'),
    $label($version('', '800', 'eu'), 'EU Records'), $europe)['album'] ?? null) === '800');
check('...the same label on both is still never guessed', $pick($label($version('', '700', 'us'), 'EU Records'),
    $label($version('', '800', 'eu'), 'EU Records'), $europe)['status'] === 'ambiguous');
check('...nor without a European edition to compare with', $pick($label($version('', '700', 'us'), 'US Records'),
    $label($version('', '800', 'eu'), 'EU Records'))['status'] === 'ambiguous');
$country = static fn (array $e, string $c) => $e + ['country' => $c];
check('an album released only for a country outside Europe gives way (Canada vs France)', ($pick($country($version('', '910', 'ca'), 'CA'),
    $country($version('', '920', 'fr'), 'FR'))['album'] ?? null) === '920');
check('...also to a worldwide or unmarked edition (Japan vs no country)', ($pick($country($version('', '930', 'jp'), 'JP'),
    $version('', '940', 'any'))['album'] ?? null) === '940');
check('...but two European editions are still not guessed', $pick($country($version('', '950', 'de'), 'DE'),
    $country($version('', '960', 'fr2'), 'FR'))['status'] === 'ambiguous');
check('...and when every album is outside Europe nothing is dropped', $pick($country($version('', '970', 'us'), 'US'),
    $country($version('', '980', 'jp2'), 'JP'))['status'] === 'ambiguous');
check('a hi-res store version comes after the main edition', ($pick($version('hi-res', '990', 'hr'), $version('', '100', 'e1'))['album'] ?? null) === '100');
check('...also "Qobuz : 24-Bit/44.1 kHz" (with a typographic hyphen)', ($pick($version("Qobuz : 24\u{2010}Bit/44.1 kHz", '991', 'q'),
    $version('', '100', 'e1'))['album'] ?? null) === '100');
check('...and after Dolby Atmos', ($pick($version('hi-res', '990', 'hr'), $version('Dolby Atmos', '300', 'e3'))['album'] ?? null) === '300');
foreach (['http://tidal.com/album/12', 'https://tidal.com.evil/album/12', 'https://tidal.com/track/12',
    'https://tidal.com/album/12?autoplay=true', 'tidal://album/12', 'https://tidal.com/album/12/u', 'https://tidal.com/album/0'] as $url) {
    check("reject non-canonical or unsafe URL $url", TidalMatch::albumId($url) === null);
}
check('missing artist identity cannot publish an automatic link', TidalMatch::assess($row, [], $response)['status'] === 'ambiguous');
check('a missing concrete edition ID cannot publish a link', $assess($mutate(['id' => '']))['status'] === 'ambiguous');
$conflict = array_replace($edition, ['barcode' => '999999999999']);
check('conflicting barcodes on one album ID are ambiguous', $assess(['release-count' => 2, 'releases' => [$edition, $conflict]])['status'] === 'ambiguous');
$conflict = array_replace($edition, ['media' => [['format' => 'Digital Media', 'track-count' => 9]]]);
check('conflicting track counts on one album ID are ambiguous', $assess(['release-count' => 2, 'releases' => [$edition, $conflict]])['status'] === 'ambiguous');
$secondArtist = 'bbbbbbbb-0000-0000-0000-000000000002';
$secondEdition = array_replace($edition, ['title' => 'Another EP', 'artist-credit' => [['artist' => ['id' => $secondArtist]]],
    'relations' => [['type' => 'streaming', 'url' => ['resource' => 'https://tidal.com/album/123']]]]);
check('an unrelated EP is matched automatically by the same general rule', TidalMatch::assess(
    ['id' => 'aaaaaaaa-0000-0000-0000-000000000002', 'title' => 'Another EP', 'first_release_date' => '2026-10-30'],
    [['mbid' => $secondArtist]], ['release-count' => 1, 'releases' => [$secondEdition]])['album'] === '123');

$pdo = Database::connect($cfg);
// Start clean: other suites leave releases in the shared test database, which would enter the queue.
foreach (['tidal_links', 'tidal_queue', 'tidal_state', 'release_artists', 'releases'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS $table");
}
$discovery = new DiscoveryStore($pdo);
$discovery->saveRelease(['id' => $id, 'title' => 'New Album', 'type' => 'Album', 'first_release_date' => '2026-10-30',
    'artist' => ['mbid' => $artist, 'name' => 'Test artist', 'primary_name' => 'Test artist', 'credited_artists' => 1,
        'credits' => [['mbid' => $artist, 'name' => 'Test artist', 'artist_name' => 'Test artist', 'joinphrase' => '']]], 'release_tags' => []], 'pool', null, $now);
$store = new TidalLinkStore($pdo);
$items = [['id' => $id, 'title' => 'New Album', 'score' => 0.4, 'tidalAlbumId' => 'untrusted-old-snapshot']];
check('before the first import stale snapshot links are cleared', $store->attach($items, $now)[0]['tidalAlbumId'] === null);
$store->install();
$pdo->exec('DELETE FROM tidal_links');
$calls = 0;
$mb = new MusicBrainzClient(transport: static function (string $url) use (&$calls, $response): array {
    $calls++;
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    if (($q['release-group'] ?? '') === 'aaaaaaaa-0000-0000-0000-000000000001') {
        return [200, json_encode($response), ''];
    }
    return [200, '{"release-count":0,"releases":[]}', ''];
});
$import = new TidalImport($pdo, $mb);
$result = $import->step($now);
check('a bounded import checks one group', $result['checked'] === 1 && $calls === 1);
check('the automatically verified result is returned on display entries', $store->attach($items, $now)[0]['tidalAlbumId'] === '556197748');
check('link decoration changes no score', $store->attach($items, $now)[0]['score'] === 0.4);
$pdo->exec('ALTER TABLE tidal_links CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
$store->install();
check('an inherited database collation is repaired without losing the verified link', $store->attach($items, $now)[0]['tidalAlbumId'] === '556197748');
check('repaired cache joins remain compatible with the existing music tables', $store->due($now) === null);

$runs = new RecommendationStore($pdo);
$runs->install();
$entry = ['id' => $id, 'title' => 'New Album', 'artist' => 'Test artist', 'artistMbid' => $artist, 'type' => 'Album',
    'releaseDate' => '2026-10-30', 'genre' => null, 'tags' => [], 'country' => null, 'origin' => 'discovery', 'dataQuality' => 'good',
    'score' => 0.4, 'pick' => ['kind' => 'recommended', 'rank' => 1, 'angle' => null], 'reasons' => [], 'sources' => ['MusicBrainz']];
$runs->publish(['releases' => [$entry], 'generatedAt' => $now, 'window' => ['2026-10-01', '2026-10-31'], 'examined' => 1, 'taste' => []], 'test', [], $now);
$feed = new CurrentFeed(static fn () => $runs, new FeedFile('/no-tidal-test-fallback'));
$links = static fn (array $items): array => $store->attach($items, $now);
$api = (new RecommendationsController($feed, $links))->index(new Request('GET', '/recommendations'));
check('the feed API decorates the cached active run', $api->data['releases'][0]['tidalAlbumId'] === '556197748');
check('stored recommendation JSON is not modified', !array_key_exists('tidalAlbumId', $runs->activeFeed()['releases'][0]));
$api = (new SearchController(static fn () => $pdo, $links))->index(new Request('GET', '/search', ['q' => 'New Album']));
check('search results receive the same verified link', $api->data['results'][0]['release']['tidalAlbumId'] === '556197748');
Schema::ensure($pdo);
$feedback = new FeedbackRepository($pdo);
$feedback->apply($id, FeedbackChange::fromInput(['rating' => 2]), $entry);
$controller = new FeedbackController(static fn () => $feedback, $feed, static fn () => true, $links);
$api = $controller->index(new Request('GET', '/feedback'));
check('My Music keeps its rating while receiving the link', $api->data['items'][0]['rating'] === 2 && $api->data['items'][0]['release']['tidalAlbumId'] === '556197748');
check('feedback snapshots are not rewritten with link metadata', !array_key_exists('tidalAlbumId', $feedback->get($id)['release']));
$pdo->prepare('UPDATE tidal_links SET approval_hash=? WHERE release_id=?')->execute([str_repeat('0', 64), $id]);
check('a generic validator fingerprint change does not retract an approval', $store->attach($items, $now)[0]['tidalAlbumId'] === '556197748');
check('cache age does not hide an approved link', $store->attach($items, '2026-11-08T12:00:00+00:00')[0]['tidalAlbumId'] === '556197748');
$pdo->prepare('UPDATE releases SET data_hash=?, release_tags=? WHERE id=?')->execute([str_repeat('a', 40), '["new genre"]', $id]);
check('irrelevant metadata changes preserve the link', $store->attach($items, $now)[0]['tidalAlbumId'] === '556197748');
check('irrelevant metadata does not force another lookup', $store->due($now) === null);
check('approved links are never due for a weekly refresh', $store->due('2026-11-08T12:00:00+00:00') === null);
// Simulate retained results from refreshes performed before this policy change.
$retry = $pdo->query('SELECT * FROM releases')->fetch();
$store->save($retry, ['status' => 'error'], '2026-11-08T12:00:00+00:00');
check('a temporary error preserves the approved link', $store->attach($items, $now)[0]['tidalAlbumId'] === '556197748');
$store->save($retry, ['status' => 'ambiguous', 'reason' => 'incomplete'], '2026-11-08T12:00:00+00:00');
check('an incomplete response cannot revoke an existing approval', $store->attach($items, $now)[0]['tidalAlbumId'] === '556197748');
$store->save($retry, ['status' => 'missing'], '2026-11-08T12:00:00+00:00');
check('a missing relation alone does not prove a previously matched album is wrong', $store->attach($items, $now)[0]['tidalAlbumId'] === '556197748');
$pdo->prepare('UPDATE releases SET title=? WHERE id=?')->execute(['Different album', $id]);
check('a concrete title change immediately hides the old link', $store->attach($items, $now)[0]['tidalAlbumId'] === null);
check('an identity change does not requeue a previously approved release', $store->due($now) === null);
$pdo->prepare('UPDATE releases SET title=? WHERE id=?')->execute(['New Album', $id]);
$store->save($retry, ['status' => 'ambiguous'], '2026-11-20T12:00:00Z');
check('a conflicting match revokes the link', $store->attach($items, $now)[0]['tidalAlbumId'] === null);
$api = $controller->index(new Request('GET', '/feedback'));
check('revocation never changes the My Music rating', $api->data['items'][0]['rating'] === 2 && $api->data['items'][0]['release']['tidalAlbumId'] === null);
check('an ambiguous result under the current rules waits for its weekly retry', $store->due('2026-11-21T12:00:00Z') === null);
$pdo->prepare('UPDATE tidal_links SET approval_hash=? WHERE release_id=?')->execute([str_repeat('0', 64), $id]);
check('an ambiguous result from earlier matching rules is due again at once', ($store->due('2026-11-21T12:00:00Z')['id'] ?? null) === $id);
$pdo->exec('DELETE FROM tidal_links');
$pdo->exec('UPDATE tidal_queue SET first_checked_at=NULL');
TidalLinkStore::$firstCheckDelayHours = 12;
check('a new release is first checked 12 hours after it was stored', $store->due($now) === null
    && ($store->due(gmdate(DATE_ATOM, strtotime($now) + 12 * 3600 + 60))['id'] ?? null) === $id);
check('...the backfill workflow checks it at once', ($store->due($now, from: '2026-10-30')['id'] ?? null) === $id);
TidalLinkStore::$firstCheckDelayHours = 0;
$retry = $store->due($now);
$store->save($retry, ['status' => 'missing'], $now);
check('a missing relation waits three days for its first retry', $store->due('2026-11-03T11:59:00Z') === null);
$outlookRun = $pdo->query("SELECT id FROM recommendation_runs WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetchColumn();
$outlookOld = $pdo->query('SELECT score FROM recommendation_scores WHERE run_id = ' . (int) $outlookRun . ' AND release_id = ' . $pdo->quote($retry['id']))->fetchColumn();
$pdo->prepare('DELETE FROM recommendation_scores WHERE run_id = ? AND release_id = ?')->execute([$outlookRun, $retry['id']]);
$pdo->prepare('INSERT INTO recommendation_scores (run_id, release_id, score, parts) VALUES (?, ?, 0.6, ?)')->execute([$outlookRun, $retry['id'], '{}']);
$outlook = $store->retryOutlook('2026-11-01T12:00:00Z');
check('the retry outlook counts a missing link waiting for its day-3 check', $outlook['due_now'] === 0 && $outlook['waiting'] >= 1
    && $outlook['within_7_days'] >= 1 && $outlook['waiting_missing'] >= 1, json_encode($outlook));
check('...and it is due on day 3', $store->retryOutlook('2026-11-03T12:01:00Z')['due_now'] >= 1);
$pdo->prepare('UPDATE recommendation_scores SET score = 0.1 WHERE run_id = ? AND release_id = ?')->execute([$outlookRun, $retry['id']]);
check('...a low score is not counted (the daily import will not retry it)', $store->retryOutlook('2026-11-01T12:00:00Z')['waiting'] === $outlook['waiting'] - 1);
$pdo->prepare('DELETE FROM recommendation_scores WHERE run_id = ? AND release_id = ?')->execute([$outlookRun, $retry['id']]);
if ($outlookOld !== false) {
    $pdo->prepare('INSERT INTO recommendation_scores (run_id, release_id, score, parts) VALUES (?, ?, ?, ?)')->execute([$outlookRun, $retry['id'], $outlookOld, '{}']);
}
$retry = $store->due('2026-11-03T12:01:00Z');
check('a missing relation becomes due again', $retry !== null);
$store->save($retry, ['status' => 'missing'], '2026-11-03T12:01:00Z');
check('missing: the next check is on day 29 after the first (day 3, 29)', $store->due('2026-11-29T11:59:00Z') === null && $store->due('2026-11-29T12:02:00Z') !== null);
$pdo->exec('UPDATE tidal_links SET daily_checks=999');
check('no daily quota stops due work', $store->due('2026-11-29T12:02:00Z') !== null);
$lowId = $store->due('2026-11-29T12:02:00Z')['id'];
$activeRun = $pdo->query("SELECT id FROM recommendation_runs WHERE status = 'active' ORDER BY id DESC LIMIT 1")->fetchColumn();
$oldScore = $pdo->query('SELECT score FROM recommendation_scores WHERE run_id = ' . (int) $activeRun . ' AND release_id = ' . $pdo->quote($lowId))->fetchColumn();
$pdo->prepare('DELETE FROM recommendation_scores WHERE run_id = ? AND release_id = ?')->execute([$activeRun, $lowId]);
$pdo->prepare('INSERT INTO recommendation_scores (run_id, release_id, score, parts) VALUES (?, ?, 0.2, ?)')->execute([$activeRun, $lowId, '{}']);
check('a release scoring below the Recommended threshold (0.495) is not retried by the daily import', ($store->due('2026-11-29T12:02:00Z')['id'] ?? null) !== $lowId);
$pdo->prepare('UPDATE recommendation_scores SET score = 0.5 WHERE run_id = ? AND release_id = ?')->execute([$activeRun, $lowId]);
check('...one scoring 0.495 or more still is', ($store->due('2026-11-29T12:02:00Z')['id'] ?? null) === $lowId);
$pdo->prepare('DELETE FROM recommendation_scores WHERE run_id = ? AND release_id = ?')->execute([$activeRun, $lowId]);
$pdo->prepare('INSERT INTO recommendation_scores (run_id, release_id, score, parts) VALUES (?, ?, 0.9, ?)')->execute([$activeRun, 'ffffffff-0000-0000-0000-000000000099', '{}']);
check('...one without a score (not judged) is not retried either', ($store->due('2026-11-29T12:02:00Z')['id'] ?? null) !== $lowId);
$pdo->prepare('DELETE FROM recommendation_scores WHERE run_id = ? AND release_id = ?')->execute([$activeRun, 'ffffffff-0000-0000-0000-000000000099']);
$pdo->prepare('INSERT INTO recommendation_scores (run_id, release_id, score, parts) VALUES (?, ?, 0.5, ?)')->execute([$activeRun, $lowId, '{}']);
$pdo->prepare('DELETE FROM recommendation_scores WHERE run_id = ? AND release_id = ?')->execute([$activeRun, $lowId]);
if ($oldScore !== false) {
    $pdo->prepare('INSERT INTO recommendation_scores (run_id, release_id, score, parts) VALUES (?, ?, ?, ?)')->execute([$activeRun, $lowId, $oldScore, '{}']);
}
$stageNow = '2026-11-02T12:00:00Z';
$source = $store->due('2026-11-29T12:02:00Z');
$cached = $pdo->query('SELECT * FROM tidal_links')->fetch();
$pdo->prepare('DELETE FROM tidal_queue WHERE release_id=?')->execute([$id]);
$store->enqueueFrom('2026-10-30', $stageNow);
$queued = $pdo->query('SELECT * FROM tidal_queue')->fetch();
check('MariaDB date registration adopts existing negative cache without resetting its retry', $queued['first_checked_at'] === $cached['checked_at']
    && $queued['requested_from'] === null && $queued['priority'] == 4 && $pdo->query('SELECT * FROM tidal_links')->fetch() === $cached);
check('a backfill never re-checks a release it has checked before (BACKFILL_RETRIES off)', TidalLinkStore::BACKFILL_RETRIES
    || ($store->due('2026-11-29T12:02:00Z', from: '2026-10-30') === null && $store->due('2026-11-29T12:02:00Z') !== null));
$recheck = static fn (string $status, string $since) => $store->due('2026-11-04T12:00:00Z', from: '2026-10-30', recheck: ['status' => $status, 'since' => $since]);
check('a requested recheck takes every release of that status, whatever its retry date', ($recheck('missing', '2026-11-04T00:00:00Z')['id'] ?? null) === $id);
check('...and only that status', $recheck('error', '2026-11-04T00:00:00Z') === null && $recheck('ambiguous', '2026-11-04T00:00:00Z') === null);
check('...and only releases checked before the run started, so each once per run', $recheck('missing', '2026-11-03T12:00:00Z') === null);
$pdo->prepare('UPDATE tidal_queue SET requested_from=? WHERE release_id=?')->execute(['2026-10-30', $id]);
$store->save($source, ['status' => 'error', 'error' => 'http_503'], $stageNow);
check('MariaDB preserves an outstanding date request across a technical failure', $pdo->query('SELECT requested_from FROM tidal_queue')->fetchColumn() === '2026-10-30');
$store->save($source, ['status' => 'verified', 'album' => '556197748', 'edition' => $editionId], $stageNow);
check('MariaDB clears a resolved date request without changing the verified link', $pdo->query('SELECT requested_from FROM tidal_queue')->fetchColumn() === null
    && $store->attach($items)[0]['tidalAlbumId'] === '556197748');
$store->save($source, ['status' => 'error', 'error' => 'http_503'], $stageNow);
$cached = $pdo->query('SELECT * FROM tidal_links')->fetch();
$store->enqueueFrom('2026-10-30', $stageNow);
check('MariaDB stage registration skips approved links even after a failed refresh', $pdo->query('SELECT * FROM tidal_links')->fetch() === $cached
    && $pdo->query('SELECT requested_from FROM tidal_queue')->fetchColumn() === null && $store->stageSummary('2026-10-30', $stageNow)['approved'] === 1);
foreach (['tidal_links', 'tidal_queue', 'tidal_state'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS $table");
}
$pdo->prepare('DELETE FROM release_artists WHERE release_id=?')->execute([$id]);
$pdo->prepare('DELETE FROM releases WHERE id=?')->execute([$id]);
foreach (['release_feedback', 'release_feedback_log', 'recommendation_items', 'recommendation_scores', 'recommendation_runs'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS $table");
}
echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
