<?php

declare(strict_types=1);

/**
 * Tests for the release search (GET /api/search) against a SEPARATE test
 * database (default music_radar_test - its tables are dropped/recreated).
 *
 *   docker compose exec php php /var/www/api/bin/test-search.php
 */

use MusicRadar\Controllers\SearchController;
use MusicRadar\Database\Database;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Feed\CurrentFeed;
use MusicRadar\Feed\FeedFile;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Http\Request;
use MusicRadar\Search\ReleaseSearch;

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
foreach (['releases', 'artists', 'slices', 'failures', 'meta', 'profiles', 'recommendation_runs', 'recommendation_items', 'recommendation_scores'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}
$store = new DiscoveryStore($pdo);

$id = static fn (int $n) => sprintf('22222222-0000-0000-0000-%012d', $n);
$artistId = static fn (int $n) => sprintf('33333333-0000-0000-0000-%012d', $n);
$rows = [];
$add = static function (int $n, string $artist, string $title, string $type, string $date, array $tags = [], ?string $watch = null) use (&$rows, $id, $artistId): void {
    $rows[] = ['id' => $id($n), 'title' => $title, 'type' => $type, 'first_release_date' => $date, 'artist_mbid' => $artistId($n),
        'artist_name' => $artist, 'primary_name' => $artist, 'credited' => 1, 'release_tags' => json_encode($tags), 'origin' => $watch ? 'known' : 'pool',
        'watch_level' => $watch, 'data_hash' => sha1((string) $n), 'first_seen_at' => '2026-10-20T10:00:00+02:00', 'last_seen_at' => '2026-10-26T10:00:00+01:00'];
};
$add(1, 'Chloe Slater', 'Riot Youth', 'Album', '2026-10-09', ['indie pop', 'pop'], 'interested');
$add(2, 'The Big Hurt', 'For The Love Of The Game', 'EP', '2026-09-29', ['hardcore punk']);
$add(3, 'Tagada Jones', 'À fleur de peau', 'Album', '2026-10-02', ['punk']);
$add(4, 'Mеgamozg', 'Война и Мир', 'Album', '2026-09-28', ['rock']);
$add(5, 'בוא לבר', 'סליחה שהרסנו', 'Album', '2026-10-16');
$add(6, 'Sødt Støj', 'Æblegrød på Østerbro', 'EP', '2026-10-21', ['indie rock']);
$add(7, 'U2', 'Days of Ash', 'EP', '2026-10-15', ['rock']);
$add(8, 'Night Rider', 'Enderlin', 'EP', '2026-10-08', ['metalcore', 'synthwave']);
$add(9, 'Coralie Fischer', 'if you’re going to be sad', 'Album', '2026-10-11');
$add(10, 'Nobody Recommended', 'Quiet Record', 'Album', '2026-09-27', ['ambient']);
for ($i = 11; $i <= 60; $i++) {
    $add($i, "Metal Band $i", "Metal Album $i", 'Album', sprintf('2026-10-%02d', 1 + $i % 25), ['metal']);
}
$store->importRows('releases', $rows);

// Active recommendations: 1 recommended, 2 recommended, 8 wildcard.
$byId = array_column($rows, null, 'id');
$entry = static fn (int $n, string $kind, int $rank) => ['id' => $id($n), 'artist' => $byId[$id($n)]['artist_name'], 'artistMbid' => null, 'title' => $byId[$id($n)]['title'], 'type' => 'Album',
    'releaseDate' => '2026-10-01', 'genre' => 'Pop', 'tags' => [], 'country' => null, 'origin' => 'discovery', 'dataQuality' => 'good',
    'pick' => ['kind' => $kind, 'angle' => null, 'rank' => $rank], 'reasons' => [['kind' => 'genres', 'text' => 'Genres you listen to: pop']], 'sources' => ['MusicBrainz']];
$feeds = new RecommendationStore($pdo);
$feeds->install();
$feeds->publish(['generatedAt' => '2026-10-26T05:30:00+01:00', 'window' => ['2026-09-26', '2026-10-26'], 'examined' => 60,
    'taste' => ['genres' => [], 'artists' => []], 'releases' => [$entry(1, 'recommended', 1), $entry(2, 'recommended', 2), $entry(8, 'wildcard', 1)]], 'test', [], date(DATE_ATOM),
    [$id(10) => ['score' => 0.31, 'scoreParts' => ['genre' => 0.31]]]);

$controller = new SearchController(static fn () => $pdo);
$search = static function (string $q) use ($controller): array {
    $r = $controller->index(new Request('GET', '/search', ['q' => $q]));
    return ['status' => $r->status] + (is_array($r->data) ? $r->data : []);
};
$titles = static fn (array $r) => array_map(static fn ($x) => $x['release']['title'], $r['results'] ?? []);
$statusOf = static fn (array $r, string $title) => (array_values(array_filter($r['results'] ?? [], static fn ($x) => $x['release']['title'] === $title))[0]['status'] ?? null);

echo "Finding releases\n";
check('artist name', $titles($search('chloe slater')) === ['Riot Youth']);
check('album title', $titles($search('riot youth')) === ['Riot Youth']);
check('EP title', $titles($search('enderlin')) === ['Enderlin']);
check('upper/lower case does not matter', $titles($search('RIOT yOuTh')) === ['Riot Youth']);
check('beginning of a word is enough', $titles($search('slat')) === ['Riot Youth']);
check('common words like "the" do not break the search', $titles($search('the big hurt')) === ['For The Love Of The Game']);
check('words in any order, artist + title mixed', $titles($search('hurt game')) === ['For The Love Of The Game']);
check('two-letter names (U2)', $titles($search('U2')) === ['Days of Ash'], json_encode($titles($search('U2'))));

echo "Special characters\n";
check('Danish letters (æ ø å)', $titles($search('æblegrød')) === ['Æblegrød på Østerbro'] && $titles($search('Sødt Støj')) === ['Æblegrød på Østerbro']);
check('accents are optional (a fleur -> À fleur)', $titles($search('a fleur de peau')) === ['À fleur de peau'] && $titles($search('à fleur')) === ['À fleur de peau']);
check('æ/ø/å are letters of their own, as in Danish ("sødt" yes, "sodt" no)', $titles($search('sødt')) === ['Æblegrød på Østerbro'] && $titles($search('sodt')) === []);
check('the middle of a word is found too ("sterbro")', $titles($search('sterbro')) === ['Æblegrød på Østerbro']);
check('Cyrillic, any case', $titles($search('ВОЙНА')) === ['Война и Мир'] && $titles($search('война и мир')) === ['Война и Мир']);
check('Hebrew', $titles($search('סליחה')) === ['סליחה שהרסנו']);
check('typographic apostrophe in titles', $titles($search('if you’re going')) === ['if you’re going to be sad'] && $titles($search("you're going")) === ['if you’re going to be sad']);
check('search syntax is plain text ("+metal -album*", quotes, %)', $search('+metal -album* "x" %')['status'] === 200 && $search('%%')['status'] === 200);

echo "Status of each result\n";
$r = $search('riot youth');
check('Recommended for You', $statusOf($r, 'Riot Youth') === 'recommended');
check('...with the existing reasons', $r['results'][0]['release']['reasons'][0]['text'] === 'Genres you listen to: pop');
check('Wildcard', $statusOf($search('night rider'), 'Enderlin') === 'wildcard');
$nr = $search('quiet record');
check('stored but not recommended', $statusOf($nr, 'Quiet Record') === 'not_recommended');
check('...with no invented reasons', $nr['results'][0]['release']['reasons'] === []);
check('...but with documented data (date, type, tags)', $nr['results'][0]['release']['releaseDate'] === '2026-09-27'
    && $nr['results'][0]['release']['type'] === 'Album' && $nr['results'][0]['release']['tags'] === ['ambient'], json_encode($nr['results'][0]['release']));
check('...marked as not a pick', $nr['results'][0]['release']['pick']['kind'] === 'none');
check('...with its current score when the run assessed it', $nr['results'][0]['release']['score'] === 0.31
    && $nr['results'][0]['release']['scoreParts'] === ['genre' => 0.31], json_encode($nr['results'][0]['release']));
$u2 = $search('U2')['results'][0]['release'];
check('...and no score when the run did not assess it', array_key_exists('score', $u2) && $u2['score'] === null, json_encode($u2));
check('releases older than the default 7 days are found', $statusOf($search('for the love'), 'For The Love Of The Game') === 'recommended');

echo "Not found, limits, input\n";
$none = $search('zzzz not a band');
check('nothing found: empty list plus what the database covers', $none['results'] === [] && $none['coverage']['releases'] === 60 && $none['coverage']['from'] === '2026-09-27', json_encode($none['coverage'] ?? null));
$many = $search('metal');
check('at most 30 results, and it says there are more', count($many['results']) === ReleaseSearch::MAX_RESULTS && $many['more'] === true);
check('newest first among equal matches', $many['results'][0]['release']['releaseDate'] >= end($many['results'])['release']['releaseDate']);
check('too short / too long is refused', $search('x')['status'] === 400 && $search(str_repeat('a', 101))['status'] === 400);
check('invalid characters (not UTF-8) are refused', $search("\xE0 fleur")['status'] === 400);
$broken = (new SearchController(static fn () => throw new PDOException('SQLSTATE[HY000] secret')))->index(new Request('GET', '/search', ['q' => 'abc']));
check('database down: 503 without details', $broken->status === 503 && !str_contains(json_encode($broken->data), 'SQLSTATE'));

echo "Index and feedback snapshots\n";
$idx = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'releases' AND index_name = 'releases_search'")->fetchColumn();
check('the full-text index was created on first use', (int) $idx > 0);
$feed = new CurrentFeed(static fn () => $feeds, new FeedFile('/nonexistent'), static function (string $rid) use ($pdo): ?array {
    $s = new ReleaseSearch($pdo);
    $row = $s->find($rid);
    return $row ? $s->toFeedEntry($row) : null;
});
check('a recommended release: snapshot from the feed (with reasons)', $feed->release($id(1))['reasons'] !== []);
check('a found, not recommended release can be rated (snapshot from the database)', ($feed->release($id(10))['title'] ?? null) === 'Quiet Record');
check('an unknown release still cannot', $feed->release('99999999-0000-0000-0000-000000000000') === null);
$t = microtime(true);
for ($i = 0; $i < 20; $i++) {
    $search('metal album');
}
check('fast: 20 searches well under a second', microtime(true) - $t < 1.0, round(microtime(true) - $t, 2) . ' s');

foreach (['releases', 'artists', 'slices', 'failures', 'meta', 'profiles', 'recommendation_runs', 'recommendation_items', 'recommendation_scores'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}
echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
