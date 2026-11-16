<?php

declare(strict_types=1);

/**
 * Tests for "Bedøm albums" (your most played albums) against a SEPARATE test
 * database (default music_radar_test). Last.fm and MusicBrainz are simulated.
 *
 *   docker compose exec php php /var/www/api/bin/test-top-albums.php
 */

use MusicRadar\Controllers\TopAlbumsController;
use MusicRadar\Database\Database;
use MusicRadar\Feedback\FeedbackChange;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Feedback\FeedbackSet;
use MusicRadar\Feedback\Schema;
use MusicRadar\Http\Request;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\TopAlbums\TopAlbums;

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
foreach (['top_albums', 'release_feedback', 'release_feedback_log'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}

echo "Resolving your most played albums (simulated Last.fm + MusicBrainz)\n";
$uuid = static fn (int $n) => sprintf('aaaaaaaa-0000-0000-0000-%012d', $n);
$artistId = 'bbbbbbbb-0000-0000-0000-000000000001';
$lastfmAlbums = [
    ['name' => 'Cruel World', 'artist' => ['name' => 'Holly Humberstone'], 'playcount' => '900', 'url' => 'x'],
    ['name' => 'A Single', 'artist' => ['name' => 'Holly Humberstone'], 'playcount' => '800', 'url' => 'x'],
    ['name' => 'Cruel World (Deluxe)', 'artist' => ['name' => 'Holly Humberstone'], 'playcount' => '50', 'url' => 'x'],
    ['name' => 'Old Record (Remastered)', 'artist' => ['name' => 'Holly Humberstone'], 'playcount' => '400', 'url' => 'x'],
];
$lfm = new LastFmClient(str_repeat('ab', 16), 10, static fn (string $url): array => [200, json_encode(['topalbums' => ['album' => $lastfmAlbums]]), '']);
$group = static fn (string $id, string $title, array $tags) => ['id' => $id, 'score' => 100, 'title' => $title, 'primary-type' => 'Album',
    'first-release-date' => '2026-01-23', 'tags' => $tags, 'artist-credit' => [['name' => 'Holly Humberstone', 'artist' => ['id' => $artistId, 'name' => 'Holly Humberstone']]]];
$mb = new MusicBrainzClient(null, 0, 15, 1, static function (string $url) use ($group, $uuid, $artistId): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $query = (string) ($q['query'] ?? '');
    if (str_starts_with($query, 'arid:')) {
        return [200, json_encode(['artists' => [['id' => $artistId, 'name' => 'Holly Humberstone', 'tags' => [['name' => 'indie pop', 'count' => 3]]]]]), ''];
    }
    $hits = match (true) {
        str_contains($query, 'releasegroup:"Cruel World"'), str_contains($query, 'releasegroup:"Cruel World (Deluxe)"')
            => [$group($uuid(1), 'Cruel World', [['name' => 'pop', 'count' => 1], ['name' => 'indie pop', 'count' => 4]])],
        str_contains($query, 'releasegroup:"Old Record"') => [$group($uuid(2), 'Old Record', [])],
        default => [],
    };
    return [200, json_encode(['count' => count($hits), 'release-groups' => $hits]), ''];
});
$log = [];
$albums = TopAlbums::resolve($lfm, $mb, 'me', static function (string $m) use (&$log): void {
    $log[] = $m;
});
check('albums and EPs only - a single is skipped', count($albums) === 2 && str_contains(implode(' ', $log), 'A Single'), json_encode($log));
check('the same album twice counts once (plays added)', $albums[0]['id'] === $uuid(1) && $albums[0]['plays'] === 950, json_encode($albums[0]));
check('most played first', $albums[0]['plays'] >= $albums[1]['plays']);
check('genre tags, strongest first', $albums[0]['tags'] === ['indie pop', 'pop'], json_encode($albums[0]['tags']));
check('an edition in the Last.fm name is tried without it (Old Record (Remastered))', ($albums[1]['title'] ?? '') === 'Old Record');
check('an album without tags gets the tags of its artist', $albums[1]['tags'] === ['indie pop'], json_encode($albums[1]['tags']));

echo "Import: adds what is missing, never changes what is there\n";
$top = new TopAlbums($pdo);
check('stored', $top->addMissing([$albums[0]], date(DATE_ATOM)) === 1 && $top->count() === 1);
$changed = ['plays' => 1] + $albums[0];
check('a new run adds only the missing album (e.g. after a MusicBrainz error)', $top->addMissing([$changed, $albums[1]], date(DATE_ATOM)) === 1 && $top->count() === 2);
check('...and leaves an album already in the list as it was', $top->all()[0]['plays'] === 950);
check('the same list again adds nothing', $top->addMissing($albums, date(DATE_ATOM)) === 0 && $top->count() === 2);
check('resolve stops at the chosen number (top N)', count(TopAlbums::resolve($lfm, $mb, 'me', static fn () => null, 1)) === 1);
$bad = null;
try {
    (new TopAlbums($pdo))->addMissing([['id' => 'not-an-id'] + $albums[0]], date(DATE_ATOM));
} catch (InvalidArgumentException $e) {
    $bad = $e;
}
check('invalid entries are refused', $bad !== null);

echo "List and ratings\n";
$list = json_decode(json_encode((new TopAlbumsController(static fn () => $pdo))->index(new Request('GET', '/top-albums'))->data), true);
check('GET /api/top-albums: most played first, with plays', $list['total'] === 2 && $list['albums'][0]['plays'] === 950
    && $list['albums'][0]['release']['id'] === $uuid(1), json_encode($list));
$entry = $top->entry($uuid(1));
check('an old album is a release like any other (artist, tags, date)', $entry['artistMbid'] === $artistId && $entry['tags'] !== []
    && $entry['releaseDate'] === '2026-01-23' && $entry['title'] === 'Cruel World' && $entry['genre'] !== null, json_encode($entry));
check('an album not in the list has no entry', $top->entry($uuid(9)) === null);
Schema::ensure($pdo);
$repo = new FeedbackRepository($pdo);
$repo->apply($uuid(1), FeedbackChange::fromInput(['rating' => 5]), $entry);
$engine = FeedbackSet::fromRepository($repo)->forEngine();
check('a rating is ordinary feedback the engine learns from (artist and tags)', ($engine['ratings'][0]['stars'] ?? null) === 5
    && $engine['ratings'][0]['artist_mbid'] === $artistId && $engine['ratings'][0]['tags'] !== [], json_encode($engine));

echo $failures === 0 ? "All tests passed.\n" : "$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
