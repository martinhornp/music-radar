<?php

declare(strict_types=1);

/**
 * Tests for collaborations (every credited artist) and the artist-interest
 * signal, against a SEPARATE test database (default music_radar_test - its
 * tables are dropped and recreated) and a simulated MusicBrainz / Last.fm:
 *
 *  - all credited artists stored in order, with join phrases (3+ artists)
 *  - a known artist credited second is found and counts
 *  - the pool and known-artist stages no longer overwrite each other
 *  - existing releases get their credits (migration), resumable
 *  - interest: gradual, recency-weighted, certain vs uncertain name matches,
 *    namesakes, your ratings - and kept apart from the score
 *
 *   docker compose exec php php /var/www/api/bin/test-collaborations.php
 */

use MusicRadar\Controllers\InternalController;
use MusicRadar\Database\Database;
use MusicRadar\Discovery\CandidateFinder;
use MusicRadar\Discovery\DiscoveryPipeline;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Http\Request;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\Recommend\ArtistInterest;
use MusicRadar\Recommend\RecommendationEngine;
use MusicRadar\Recommend\TasteModel;
use MusicRadar\Taste\ListeningLibrary;

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
foreach (['releases', 'artists', 'slices', 'failures', 'meta', 'profiles', 'release_artists', 'listening_artists'] as $t) {
    $pdo->exec("DROP TABLE IF EXISTS $t");
}
$discCfg = require __DIR__ . '/../config/discovery.php';
$recCfg = require __DIR__ . '/../config/recommender.php';

// --- a simulated world ------------------------------------------------------------
$today = '2026-10-26';
$day = static fn (int $daysAgo) => date('Y-m-d', strtotime("$today -$daysAgo days"));
$uuid = static fn (string $prefix, int $n) => sprintf('%s-0000-0000-0000-%012d', $prefix, $n);
$KORD = $uuid('aaaaaaaa', 1);   // interested (profile) - only ever credited second or later
$FOLLOW = $uuid('aaaaaaaa', 2); // followed
$BIG = $uuid('cccccccc', 1);    // unknown, credited first with Kord
$ALPHA = $uuid('cccccccc', 2);
$BETA = $uuid('cccccccc', 3);
$GAMMA = $uuid('cccccccc', 4);
$PAAK = $uuid('cccccccc', 5);   // not in the profile, but played on Last.fm
$ALMA2 = $uuid('cccccccc', 6);  // an "ALMA" - not the one played on Last.fm
$ALMA1 = $uuid('dddddddd', 1);  // the "ALMA" played on Last.fm
$VANT = $uuid('cccccccc', 7);   // "Vantage" - Last.fm has no MusicBrainz id
$names = [$KORD => 'Kord', $FOLLOW => 'Follow Me', $BIG => 'Big Name', $ALPHA => 'Alpha', $BETA => 'Beta', $GAMMA => 'Gamma',
    $PAAK => 'Paak', $ALMA2 => 'ALMA', $VANT => 'Vantage'];
$hiphop = ['hip hop', 'rap', 'west coast hip hop'];
$tags = [$KORD => $hiphop, $FOLLOW => ['hardcore', 'metallic hardcore'], $BIG => $hiphop, $ALPHA => ['pop', 'synthpop'], $BETA => ['pop'], $GAMMA => ['pop'],
    $PAAK => ['soul', 'funk', 'r&b'], $ALMA2 => ['pop', 'electropop'], $VANT => ['techno']];
$credit = static fn (array $parts) => array_map(static fn ($p) => ['name' => $p[1] ?? $names[$p[0]], 'joinphrase' => $p[2] ?? '',
    'artist' => ['id' => $p[0], 'name' => $names[$p[0]]]], $parts);
$group = static fn (string $id, string $title, string $date, array $credits, string $type = 'Album') => [
    'id' => $id, 'title' => $title, 'primary-type' => $type, 'first-release-date' => $date, 'secondary-types' => [], 'tags' => [],
    'artist-credit' => $credits,
];
$COLLAB = $uuid('11111111', 1);
$FOUR = $uuid('11111111', 2);
$ALMAREL = $uuid('11111111', 3);
$VANTREL = $uuid('11111111', 4);
$PAAKREL = $uuid('11111111', 5);
$world = [
    'groups' => [
        $group($COLLAB, 'Heavy Crown', $day(2), $credit([[$BIG, null, ' & '], [$KORD]])),
        $group($FOUR, 'Four Of Us', $day(3), $credit([[$ALPHA, null, ', '], [$BETA, null, ' & '], [$GAMMA, null, ' feat. '], [$KORD, 'KORD']]), 'EP'),
        $group($ALMAREL, 'Namesake', $day(4), $credit([[$ALMA2]])),
        $group($VANTREL, 'Only A Name', $day(4), $credit([[$VANT]])),
        $group($PAAKREL, 'Listened', $day(5), $credit([[$PAAK, null, ' x '], [$BIG]])),
    ],
    'requests' => [],
];
$mbTransport = static function (string $url) use (&$world, $names, $tags): array {
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $q);
    $path = substr($parts['path'], strlen('/ws/2/'));
    $world['requests'][] = $path . (isset($q['query']) ? '?' . $q['query'] : '');
    $json = static fn ($d) => [200, json_encode($d), ''];
    $hasArtist = static fn ($g, $id) => in_array($id, array_map(static fn ($c) => $c['artist']['id'], $g['artist-credit']), true);
    if ($path === 'release' && isset($q['query'])) {
        return [200, json_encode(['count' => 0, 'releases' => []]), ''];
    }
    if ($path === 'release-group' && isset($q['query']) && preg_match('/^rgid:\((.*)\)$/', $q['query'], $m)) {
        $ids = explode(' OR ', $m[1]);
        return $json(['count' => 0, 'release-groups' => array_values(array_filter($world['groups'], static fn ($g) => in_array($g['id'], $ids, true)))]);
    }
    if ($path === 'release-group' && isset($q['query'])) {
        preg_match('/firstreleasedate:\[(\S+) TO (\S+)\]/', $q['query'], $m);
        preg_match('/primarytype:(\w+)/', $q['query'], $t);
        $hits = array_values(array_filter($world['groups'], static fn ($g) => $g['first-release-date'] >= $m[1] && $g['first-release-date'] <= $m[2]
            && strcasecmp($g['primary-type'], $t[1] ?? '') === 0 && !in_array($g['id'], $world['unindexed'] ?? [], true)));
        return $json(['count' => count($hits), 'release-groups' => array_slice($hits, (int) ($q['offset'] ?? 0), 100)]);
    }
    if ($path === 'release-group' && isset($q['artist'])) {
        // Like MusicBrainz: every release group the artist is credited on, at any position.
        $hits = array_values(array_filter($world['groups'], static fn ($g) => $hasArtist($g, $q['artist'])));
        if (($q['inc'] ?? '') !== 'artist-credits') {
            $hits = array_map(static fn ($g) => array_diff_key($g, ['artist-credit' => 1]), $hits);
        }
        return $json(['release-group-count' => count($hits), 'release-groups' => $hits]);
    }
    if (str_starts_with($path, 'release-group/')) {
        $g = array_values(array_filter($world['groups'], static fn ($g) => $g['id'] === substr($path, 14)))[0] ?? null;
        return $g === null ? [404, '{"error":"Not Found"}', ''] : $json($g + ['releases' => []]);
    }
    if ($path === 'artist' && isset($q['query'])) {
        preg_match_all('/[0-9a-f-]{36}/', $q['query'], $ids);
        return $json(['artists' => array_map(static fn ($id) => ['id' => $id, 'name' => $names[$id] ?? 'X', 'country' => 'US',
            'tags' => array_map(static fn ($t) => ['name' => $t, 'count' => 1], $tags[$id] ?? [])], array_values(array_intersect($ids[0], array_keys($names))))]);
    }
    return [404, '{}', ''];
};
$lfmTransport = static function (string $url) use (&$world, $names, $tags): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $world['requests'][] = 'lastfm:' . $q['method'] . ':' . ($q['artist'] ?? '');
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
    'genres' => [['name' => 'hip hop', 'weight' => 0.2], ['name' => 'rap', 'weight' => 0.1], ['name' => 'west coast hip hop', 'weight' => 0.05],
        ['name' => 'hardcore', 'weight' => 0.2], ['name' => 'metallic hardcore', 'weight' => 0.05], ['name' => 'soul', 'weight' => 0.02]],
    'families' => [['name' => 'Hip hop', 'weight' => 0.4], ['name' => 'Hardcore', 'weight' => 0.4], ['name' => 'R&B & soul', 'weight' => 0.05]],
    'attributes' => ['danish_share' => 0.0],
    'artists' => [
        ['name' => 'Kord', 'musicbrainz' => ['mbid' => $KORD, 'status' => 'confirmed'], 'watch' => ['level' => 'interested'],
            'lastfm' => ['listening' => 'current', 'periods' => ['overall' => ['plays' => 300]]],
            'genres' => ['weights' => ['hip hop' => 0.5, 'west coast hip hop' => 0.5], 'families' => ['Hip hop' => 1.0]]],
        ['name' => 'Follow Me', 'musicbrainz' => ['mbid' => $FOLLOW, 'status' => 'confirmed'], 'watch' => ['level' => 'following'],
            'lastfm' => ['listening' => 'core', 'periods' => ['overall' => ['plays' => 900]]],
            'genres' => ['weights' => ['hardcore' => 1.0], 'families' => ['Hardcore' => 1.0]]],
    ],
];
// The Last.fm library: Paak with a matching id hint, the other "ALMA", Vantage by name only.
$library = ListeningLibrary::identify([
    ['name' => 'Kord', 'name_key' => 'kord', 'lastfm_mbid' => $KORD, 'mbid' => '', 'identity' => '', 'rank' => 5, 'plays' => ['overall' => 300, '12month' => 80, '3month' => 20]],
    ['name' => 'Paak', 'name_key' => 'paak', 'lastfm_mbid' => $PAAK, 'mbid' => '', 'identity' => '', 'rank' => 400, 'plays' => ['overall' => 60, '12month' => 30, '3month' => 10]],
    ['name' => 'ALMA', 'name_key' => 'alma', 'lastfm_mbid' => $ALMA1, 'mbid' => '', 'identity' => '', 'rank' => 300, 'plays' => ['overall' => 90, '12month' => 40, '3month' => 12]],
    ['name' => 'Vantage', 'name_key' => 'vantage', 'lastfm_mbid' => '', 'mbid' => '', 'identity' => '', 'rank' => 500, 'plays' => ['overall' => 50, '12month' => 30, '3month' => 10]],
], $profile);

$store = new DiscoveryStore($pdo);
$store->replaceListening($library, 'test', date(DATE_ATOM));
$pipeline = static fn (array $cfg = []) => new DiscoveryPipeline($store, new MusicBrainzClient(null, 0, 15, 1, $mbTransport),
    new LastFmClient(str_repeat('ab', 16), 10, $lfmTransport), new RecommendationEngine(TasteModel::fromProfile($profile, $recCfg), $recCfg),
    $profile, $cfg + $discCfg, interest: new ArtistInterest($profile, $store->listening(), $recCfg['interest']));
$age = static fn () => $pdo->exec("UPDATE slices SET collected_at = '2026-01-01T00:00:00+01:00'") + $pdo->exec("UPDATE meta SET v = '2026-01-01T00:00:00+01:00' WHERE k LIKE 'known_checked%'");

echo "Credits from MusicBrainz\n";
$a = CandidateFinder::artist($world['groups'][1]['artist-credit']);
check('every credited artist, in order, with join phrases', array_column($a['credits'], 'mbid') === [$ALPHA, $BETA, $GAMMA, $KORD]
    && array_column($a['credits'], 'joinphrase') === [', ', ' & ', ' feat. ', ''], json_encode($a['credits']));
check('the display name is the credit as written', $a['name'] === 'Alpha, Beta & Gamma feat. KORD');
check('the name as credited and the artist\'s own name are both kept', $a['credits'][3]['name'] === 'KORD' && $a['credits'][3]['artist_name'] === 'Kord');
check('first credited artist = the release\'s main artist (genre data, Last.fm lookups)', $a['mbid'] === $ALPHA && $a['primary_name'] === 'Alpha' && $a['credited_artists'] === 4);

echo "Import: pool and known artists agree\n";
$run = $pipeline()->run($today);
$collab = $store->release($COLLAB);
check('collaboration stored once, with its full credit', $collab['artist_name'] === 'Big Name & Kord' && $collab['artist_mbid'] === $BIG && (int) $collab['credited'] === 2);
check('...found by the pool AND as a release of the known artist credited second', $collab['origin'] === 'both', $collab['origin']);
check('...credited artists stored in order', array_column($store->credits([$COLLAB])[$COLLAB], 'artist_name') === ['Big Name', 'Kord']);
check('a known artist credited second makes it "interested"', $collab['watch_level'] === 'interested');
check('one known artist among three unknown ones (4th credit) counts too', $store->release($FOUR)['watch_level'] === 'interested');
$rec = array_column($run['recommended'], null, 'id');
check('both are always included as Interested', ($rec[$COLLAB]['origin'] ?? null) === 'interested' && ($rec[$FOUR]['origin'] ?? null) === 'interested', json_encode(array_keys($rec)));
check('the reason names the artist you know, not the first one', str_contains($rec[$COLLAB]['reasons'][0]['text'] ?? '', 'Kord') && !str_contains($rec[$COLLAB]['reasons'][0]['text'] ?? '', 'Big Name'),
    $rec[$COLLAB]['reasons'][0]['text'] ?? '');

$store->setLastfmAlbum($COLLAB, ['name' => 'Heavy Crown', 'description' => '', 'tags' => ['hip hop']], 'found', date(DATE_ATOM));
$version = $store->meta('data_version');
$age();
$world['requests'] = [];
$run2 = $pipeline()->run($today);
$again = $store->release($COLLAB);
check('next import: the artist does not flip ("Big Name & Kord" stays)', $again['artist_name'] === 'Big Name & Kord' && $again['artist_mbid'] === $BIG);
check('...Last.fm album data is kept', $again['lastfm_album_status'] === 'found');
check('...nothing counts as changed (no needless recalculation)', $run2['stats']['stages']['collect']['changed'] === 0 && $run2['stats']['stages']['known']['changed'] === 0
    && $store->meta('data_version') === $version, json_encode([$run2['stats']['stages']['collect'], $run2['stats']['stages']['known'], $version, $store->meta('data_version')]));
check('no duplicate rows', (int) $pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn() === 5
    && (int) $pdo->query("SELECT COUNT(*) FROM release_artists WHERE release_id = '$COLLAB'")->fetchColumn() === 2);

echo "Migration: existing releases get their credits\n";
// As stored before this change: the known-artist stage had replaced the artist,
// and no credited artists were kept.
$pdo->exec("UPDATE releases SET artist_name = 'Kord', artist_mbid = '$KORD', primary_name = 'Kord', credited = 1, origin = 'known' WHERE id = '$COLLAB'");
$pdo->exec('DELETE FROM release_artists');
$world['requests'] = [];
$age();
$pdo->exec("UPDATE slices SET collected_at = '" . date(DATE_ATOM) . "'"); // only the credits stage is due
$pdo->exec("UPDATE meta SET v = '" . date(DATE_ATOM) . "' WHERE k LIKE 'known_checked%'");
$run3 = $pipeline()->run($today);
$c = $run3['stats']['stages']['credits'];
check('single-artist pool releases: from what is stored, no request', $c['from_store'] === 2, json_encode($c));
// The two collaborations found by the known-artist stage, and "Paak x Big Name".
check('collaborations and known-artist releases: from MusicBrainz, in one batch request', $c['from_musicbrainz'] === 3 && $c['requests'] === 1 && $c['corrected'] === 1, json_encode($c));
$fixed = $store->release($COLLAB);
check('the overwritten artist is corrected', $fixed['artist_name'] === 'Big Name & Kord' && $fixed['artist_mbid'] === $BIG && (int) $fixed['credited'] === 2, json_encode($fixed));
check('...and still counts as Interested (via Kord)', $fixed['watch_level'] === 'interested');
check('...its Last.fm album data was looked up by "Kord", so it is dropped and fetched again', $fixed['lastfm_album_status'] !== 'found');
check('every release now has its credits', $store->releasesWithoutCredits(100) === []);
$world['requests'] = [];
$run4 = $pipeline()->run($today);
check('resumable: a second run fetches nothing again', $run4['stats']['stages']['credits']['missing'] === 0
    && array_filter($world['requests'], static fn ($r) => str_starts_with($r, 'release-group?rgid')) === []);

echo "A release whose date moved (postponed) leaves its old day\n";
$GONE = $uuid('11111111', 9);
$store->saveRelease(['id' => $GONE, 'title' => 'Deleted', 'type' => 'Album', 'first_release_date' => $day(4),
    'artist' => CandidateFinder::artist($credit([[$VANT]])), 'release_tags' => []], 'pool', null, date(DATE_ATOM));
$namesake = array_search($ALMAREL, array_column($world['groups'], 'id'), true);
$world['groups'][$namesake]['first-release-date'] = '2026-11-27'; // postponed after it was collected
$world['unindexed'] = [$VANTREL];                                    // the search index lags behind for this one
$version = $store->meta('data_version');
$age();
$world['requests'] = [];
$run5 = $pipeline()->run($today);
$collect = $run5['stats']['stages']['collect'];
check('the postponed release is looked up and gets its new date', $store->release($ALMAREL)['first_release_date'] === '2026-11-27' && $collect['date_changed'] === 1,
    json_encode($collect));
check('...so it is no longer a release of the window (not recommended as out)', !isset($run5['assessments'][$ALMAREL]));
check('...and the data counts as changed (recalculation)', $store->meta('data_version') !== $version);
$lookups = array_values(array_filter($world['requests'], static fn ($r) => str_starts_with($r, 'release-group?rgid:') && str_contains($r, $ALMAREL)));
check('...the missing ones looked up by id, together in one request', count($lookups) === 1 && str_contains($lookups[0], $VANTREL) && str_contains($lookups[0], $GONE),
    json_encode($world['requests']));
check('a release only missing from the search index keeps its date', $store->release($VANTREL)['first_release_date'] === $day(4));
check('a deleted release group is left as it is, without an error', $store->release($GONE)['first_release_date'] === $day(4)
    && $collect['errors'] === 0 && $store->failures() === [], json_encode($store->failures()));
check('only the missing ones are looked up (3), nothing else', $collect['rechecked'] === 3, json_encode($collect));
check('the rest of the day is unchanged', $store->release($COLLAB)['first_release_date'] === $day(2) && $store->release($FOUR)['first_release_date'] === $day(3));
$world['groups'][$namesake]['first-release-date'] = $day(4);
$world['unindexed'] = [];
$pdo->exec("DELETE FROM releases WHERE id = '$GONE'");
$pdo->exec("UPDATE releases SET first_release_date = '{$day(4)}' WHERE id = '$ALMAREL'");

echo "Artist interest\n";
$interest = new ArtistInterest($profile, $store->listening(), $recCfg['interest']);
$credits = static fn (array $mbids) => array_map(static fn ($m) => ['mbid' => $m, 'name' => $names[$m], 'artist_name' => $names[$m]], $mbids);
$paak = $interest->forRelease($credits([$PAAK, $BIG]));
check('Last.fm id hint + same name: a certain match ("listened")', $paak['level'] === 'listened' && $paak['artists'][0]['match'] === 'confirmed' && $paak['value'] > 0.5, json_encode($paak));
$alma = $interest->forRelease($credits([$ALMA2]));
check('namesake: same name, Last.fm points to another artist - no interest', $alma['level'] === 'unknown' && $alma['value'] === 0.0 && $alma['artists'][0]['match'] === 'conflict', json_encode($alma));
$vant = $interest->forRelease($credits([$VANT]));
$sureVant = (new ArtistInterest($profile, [['lastfm_mbid' => $VANT] + $store->listening()[3]], $recCfg['interest']))->forRelease($credits([$VANT]));
check('name only (Last.fm has no id): counts, but less than a certain match', $vant['artists'][0]['match'] === 'name_only' && $vant['value'] > 0 && $vant['value'] < $sureVant['value'],
    json_encode([$vant, $sureVant]));
check('...and its reason says it is uncertain', str_contains(ArtistInterest::reason($vant)['text'] ?? '', 'matched by name only') && ArtistInterest::reason($vant)['certain'] === false);
$both = $interest->forRelease($credits([$PAAK, $VANT]));
check('every credited artist adds interest (two played artists > one)', $both['value'] > $paak['value'] && $both['value'] <= 1.0);
check('a profile artist anywhere in the credit sets the watch level', $interest->forRelease($credits([$BIG, $FOLLOW, $KORD]))['level'] === 'following');

$value = static function (int $overall, int $year, int $quarter) use ($profile, $recCfg, $credits, $PAAK): float {
    $lib = [['name' => 'Paak', 'name_key' => 'paak', 'lastfm_mbid' => $PAAK, 'mbid' => '', 'identity' => 'lastfm_mbid', 'rank' => 1,
        'plays' => ['overall' => $overall, '12month' => $year, '3month' => $quarter]]];
    return (new ArtistInterest($profile, $lib, $recCfg['interest']))->forRelease($credits([$PAAK]))['value'];
};
$curve = array_map(static fn ($n) => $value($n, 0, 0), [1, 2, 5, 9, 10, 11, 20, 50, 100]);
$steps = array_map(static fn ($a, $b) => $b - $a, array_slice($curve, 0, -1), array_slice($curve, 1));
check('gradual: more plays, a bit more interest - no jump at 10 plays', min($steps) > 0 && max($steps) < 0.06, json_encode($curve));
check('recent plays count more than old ones', $value(60, 30, 10) > $value(60, 0, 0) * 2, json_encode([$value(60, 30, 10), $value(60, 0, 0)]));
check('a handful of old plays is a weak signal', $value(6, 0, 0) < 0.10, (string) $value(6, 0, 0));
check('a single play (an autoplay?) is not enough to count as "listened"', $value(1, 1, 1) < $recCfg['interest']['listened_min'], (string) $value(1, 1, 1));
$disliked = (new ArtistInterest($profile, $store->listening(), $recCfg['interest'], [['artist_mbid' => $PAAK, 'stars' => 2], ['artist_mbid' => $PAAK, 'stars' => 1]]))
    ->forRelease($credits([$PAAK]));
check('rated low by you: your ratings outweigh the plays (no interest)', $disliked['value'] === 0.0 && $disliked['artists'][0]['disliked']);
$liked = (new ArtistInterest($profile, $store->listening(), $recCfg['interest'], [['artist_mbid' => $PAAK, 'stars' => 4]]))->forRelease($credits([$PAAK]));
check('...rated well: unchanged (the rating itself already counts in the score)', $liked['value'] === $paak['value']);

echo "Plays in the score (dynamic blend): they raise it, ratings take precedence\n";
$taste = TasteModel::fromProfile($profile, $recCfg);
$release = static fn (string $id, array $tagList, array $extra = []) => $extra + [
    'id' => $id, 'title' => "T $id", 'type' => 'Album', 'first_release_date' => '2026-10-10',
    'artist' => ['name' => 'Someone', 'mbid' => "mbid-$id", 'primary_name' => 'Someone', 'credited_artists' => 1, 'credits' => []],
    'facts' => [['id' => 'F1', 'source' => 'musicbrainz:artist', 'text' => 'Artist genres/tags: ' . implode(', ', $tagList)]],
    'metadata_quality' => 'good', 'country' => null,
];
$near = $release('near', ['soul', 'funk', 'r&b']);
$plain = new RecommendationEngine($taste, $recCfg);
$fit = $plain->assess($near)['fit'];
// Thresholds around this release's own fit, so the test does not depend on the profile's exact numbers.
$cfg = ['recommended' => ['min_fit' => $fit + 0.05] + $recCfg['recommended'], 'interest' => ['assist_min_fit' => $fit - 0.05] + $recCfg['interest']] + $recCfg;
$engine = new RecommendationEngine($taste, $cfg);
$strong = ['level' => 'listened', 'value' => 0.9, 'watch' => null, 'artists' => [['name' => 'Someone', 'match' => 'confirmed', 'value' => 0.9,
    'plays' => ['overall' => 120, '12month' => 40, '3month' => 10]]]];
$weak = ['value' => 0.2] + $strong;
$res = $engine->run(['near' => $near, 'helped' => ['id' => 'helped', 'interest' => $strong] + $near, 'weak' => ['id' => 'weak', 'interest' => $weak] + $near]);
$picked = array_column($res['recommended'], null, 'id');
check('a near miss without interest is not recommended', !isset($picked['near']));
check('...the same music by an artist you play a lot is (plays raise the score)', isset($picked['helped']) && $picked['helped']['fit'] > $fit);
check('...a little listening raises it less', $res['assessments']['weak']['fit'] < $res['assessments']['helped']['fit']);
check('the score parts still add up to the score', abs(array_sum($picked['helped']['score_parts']) - $picked['helped']['fit']) < 0.001,
    json_encode($picked['helped']['score_parts']));
check('...it is not presented as Following/Interested (sorted with the others)', $picked['helped']['origin'] === 'discovery');
check('...and it says why: that you play the artist, and how recently', ($picked['helped']['reasons'][0]['text'] ?? '') === 'You have played Someone (recently) on Last.fm',
    $picked['helped']['reasons'][0]['text'] ?? '');
$reasonTexts = json_encode(array_column($res['recommended'], 'reasons'));
check('...without play counts (the site is public)', !preg_match('/\d+ plays?/', $reasonTexts), $reasonTexts);
$far = $release('far', ['classical', 'orchestral', 'baroque']);
$farRes = $engine->run(['far' => ['interest' => ['value' => 1.0] + $strong] + $far]);
check('music that does not fit at all is never lifted to Recommended by plays alone',
    (new RecommendationEngine($taste, $recCfg))->assess(['interest' => ['value' => 1.0] + $strong] + $far)['fit'] < $recCfg['recommended']['min_fit']);
$ni = new RecommendationEngine($taste, $cfg, ['not_interested_releases' => ['helped']]);
$niRes = $ni->run(['helped' => ['id' => 'helped', 'interest' => $strong] + $near, 'other' => ['id' => 'other', 'interest' => $strong] + $near]);
check('Not interested hides only that release - not the artist', !in_array('helped', array_column($niRes['recommended'], 'id'), true)
    && in_array('other', array_column($niRes['recommended'], 'id'), true));
$off = new RecommendationEngine($taste, ['interest' => ['enabled' => false] + $cfg['interest']] + $cfg);
check('interest can be switched off in config', $off->assess(['id' => 'helped', 'interest' => $strong] + $near)['fit'] === $fit);

echo "An artist you have listened to: a wildcard of its own, never a recommendation\n";
// Around this release's own fit: too weak for Recommended, no ordinary bet (max_fit below
// the fit), and a wildcard's min_fit that only strong listening (0.15 x 0.9) reaches.
$lcfg = ['recommended' => ['min_fit' => $fit + 0.5] + $recCfg['recommended'], 'interest' => ['assist_min_fit' => 1.0] + $recCfg['interest'],
    'wildcards' => ['min_fit' => $fit + 0.1, 'max_fit' => $fit - 0.01] + $recCfg['wildcards']] + $recCfg;
$known = static fn (string $id, array $interest) => ['id' => $id, 'interest' => $interest] + $near;
$nameOnly = ['artists' => [['match' => 'name_only'] + $strong['artists'][0]]] + $strong;
$lres = (new RecommendationEngine($taste, $lcfg))->run(['strong' => $known('strong', $strong), 'weak' => $known('weak', $weak), 'plain' => $near,
    'nameonly' => $known('nameonly', $nameOnly)]);
$lw = array_column($lres['wildcards'], null, 'id');
check('strong, recent listening makes a near miss a wildcard ("Artist you know")', ($lw['strong']['angle'] ?? null) === 'known-artist', json_encode(array_keys($lw)));
check('...that says it is about your listening, not a strong match', str_starts_with($lw['strong']['reasons'][0]['text'] ?? '', 'You have played Someone (recently) on Last.fm - not a strong match'),
    $lw['strong']['reasons'][0]['text'] ?? '');
check('a faint listening signal is not enough', !isset($lw['weak']));
check('an artist matched by name only is not enough', !isset($lw['nameonly']));
check('without listening it stays not recommended', !isset($lw['plain']) && $lres['assessments']['plain']['decision'] === 'not_selected');
check('it never becomes a recommendation, and appears only once', $lres['recommended'] === [] && count($lres['wildcards']) === count(array_unique(array_column($lres['wildcards'], 'id'))));
$lowRes = (new RecommendationEngine($taste, $lcfg, ['ratings' => [['release_id' => 'strong', 'stars' => 2, 'tags' => ['soul'], 'artist_mbid' => 'x']],
    'not_interested_releases' => ['ni']]))->run(['strong' => $known('strong', $strong), 'ni' => $known('ni', $strong)]);
check('your 1-2 stars or Not interested on the release still win', $lowRes['wildcards'] === []);
$noGenre = ['id' => 'ng', 'interest' => $strong, 'facts' => [], 'metadata_quality' => 'insufficient'] + $near;
check('no genre data at all: no musical anchor, no wildcard', (new RecommendationEngine($taste, $lcfg))->run(['ng' => $noGenre])['wildcards'] === []);
$loff = ['interest' => ['listened_wildcards' => false] + $lcfg['interest']] + $lcfg;
check('can be switched off in config', (new RecommendationEngine($taste, $loff))->run(['strong' => $known('strong', $strong)])['wildcards'] === []);

echo "Last.fm data for releases by artists you have listened to\n";
$lf = $run['stats']['stages']['lastfm'];
// Paak (certain) and Vantage (name only, but played a lot) - not the other ALMA.
check('selected because of the artist interest - not for a namesake', ($lf['reasons']['listened artist'] ?? 0) === 2, json_encode($lf['reasons']));

echo "Uploading the Last.fm library (weekly refresh)\n";
$token = str_repeat('t', 40);
$ctrl = new InternalController(['import' => ['token' => $token]], static fn () => $pdo, __DIR__ . '/..');
$post = static fn (mixed $body, string $tok = '') => $ctrl->saveLibrary(new Request('POST', '/internal/library', [], is_string($body) ? $body : json_encode($body), [],
    ['x-import-token' => $tok ?: $token]));
$entry = ['name' => 'New Artist', 'lastfm_mbid' => '', 'mbid' => '', 'identity' => 'name_only', 'rank' => 1, 'plays' => ['overall' => 3, '12month' => 3, '3month' => 1]];
check('without the token: 404, nothing changed', $post(['artists' => [$entry]], str_repeat('x', 40))->status === 404 && count($store->listening()) === 4);
check('not a library: 400', $post('{"artists": {}}')->status === 400 && $post('nonsense')->status === 400);
check('one invalid entry rejects the whole upload', $post(['artists' => [$entry, ['plays' => ['overall' => -1]] + $entry]])->status === 400
    && $post(['artists' => [['mbid' => "x'; DROP TABLE releases; --"] + $entry]])->status === 400 && count($store->listening()) === 4);
$ok = $post(['artists' => [$entry, ['name' => 'ALMA', 'lastfm_mbid' => $ALMA1, 'identity' => 'lastfm_mbid'] + $entry]]);
check('a valid upload replaces the library', $ok->status === 200 && $ok->data['artists'] === 2 && count($store->listening()) === 2
    && array_diff(['alma', 'new artist'], array_column($store->listening(), 'name_key')) === [],
    json_encode([$ok->status, $ok->data, array_column($store->listening(), 'name')]));
check('...and the music data is untouched', (int) $pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn() === 5);

echo "Internal status: artists per level, from the stored data\n";
$ranked = static fn (string $name, string $mbid, string $level, array $periods, bool $taste = true) => ['name' => $name, 'musicbrainz' => ['mbid' => $mbid],
    'watch' => ['level' => $level, 'source' => $level === 'ignored' ? 'manual' : 'lastfm'], 'lastfm' => ['periods' => $periods, 'taste' => $taste]];
$store->saveProfile(['genres' => [], 'families' => [], 'artists' => [
    $ranked('Kord', $KORD, 'interested', ['overall' => ['rank' => 5, 'plays' => 300]]),
    $ranked('Follow Me', $FOLLOW, 'following', ['3month' => ['rank' => 2, 'plays' => 90]]),
    $ranked('Deep Cut', $uuid('eeeeeeee', 1), 'interested', ['12month' => ['rank' => 60, 'plays' => 40]], false),
    $ranked('Old Love', $uuid('eeeeeeee', 2), 'interested', []),                                 // kept, no current rank
    $ranked('Skipped', $uuid('eeeeeeee', 3), 'ignored', ['overall' => ['rank' => 70, 'plays' => 80]], false), // a top-100 artist that is not Interested
]], 'test', '2026-10-28T03:00:00+01:00');
$plays = static fn (int $o, int $y, int $q) => ['overall' => $o, '12month' => $y, '3month' => $q];
$store->replaceListening([
    ['name' => 'Kord', 'name_key' => 'kord', 'lastfm_mbid' => $KORD, 'mbid' => $KORD, 'identity' => 'profile', 'plays' => $plays(300, 80, 20)],      // Interested, not Listened
    ['name' => 'Paak', 'name_key' => 'paak', 'lastfm_mbid' => $PAAK, 'mbid' => '', 'identity' => 'lastfm_mbid', 'plays' => $plays(60, 30, 10)],   // Listened
    ['name' => 'Vantage', 'name_key' => 'vantage', 'lastfm_mbid' => '', 'mbid' => '', 'identity' => 'name_only', 'plays' => $plays(50, 30, 10)], // Listened (name only)
    ['name' => 'Once', 'name_key' => 'once', 'lastfm_mbid' => '', 'mbid' => '', 'identity' => 'name_only', 'plays' => $plays(1, 1, 1)],          // a single play: not Listened
], 'test', date(DATE_ATOM));
$status = static fn (string $tok) => $ctrl->status(new Request('GET', '/internal/status', [], '', [], ['x-import-token' => $tok]));
check('status without a valid token: 404, nothing revealed', $status(str_repeat('x', 40))->status === 404 && $status('')->status === 404
    && !isset($status('short')->data['artist_levels']));
$levels = $status($token)->data['artist_levels'] ?? [];
check('with the token: Following and Interested counted from the stored profile', ($levels['following'] ?? null) === 1 && $levels['interested'] === 3
    && $levels['other_levels'] === ['ignored' => 1], json_encode($levels));
check('...taste profile and unique top-100 artists', $levels['taste_artists'] === 3 && $levels['top100_unique'] === 4, json_encode($levels));
check('...Listened from the stored Last.fm library: played enough, not already Interested', $levels['listened'] === 2
    && $levels['listened_by_identity'] === ['lastfm_mbid' => 1, 'name_only' => 1] && $levels['library_artists'] === 4, json_encode($levels));
check('...when the profile was last refreshed', $levels['profile_updated_at'] === '2026-10-28T03:00:00+01:00');
$tidal = $status($token)->data['tidal'] ?? null;
check('status includes the TIDAL overview of all stored releases', is_array($tidal) && $tidal['total'] > 0
    && $tidal['total'] === $tidal['approved'] + $tidal['missing'] + $tidal['ambiguous'] + $tidal['error'] + $tidal['unchecked'], json_encode($tidal));
check('a top-100 artist that is not Interested is reported with its id - not changed', $levels['top100_check']['ok'] === false
    && $levels['top100_check']['not_interested'] === [['name' => 'Skipped', 'mbid' => $uuid('eeeeeeee', 3), 'level' => 'ignored', 'source' => 'manual']]
    && $store->latestProfile()['profile']['artists'][4]['watch']['level'] === 'ignored');

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
