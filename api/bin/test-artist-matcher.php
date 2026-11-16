<?php

declare(strict_types=1);

/**
 * Offline tests for the MusicBrainz matching rules (no network).
 * Fixtures mirror real MusicBrainz data seen for the listener's artists.
 *
 *   docker compose exec php php /var/www/api/bin/test-artist-matcher.php
 */

use MusicRadar\Discovery\KnownArtistReleases;
use MusicRadar\Taste\ArtistMatch;
use MusicRadar\Taste\ArtistMatcher;
use MusicRadar\Taste\FollowPolicy;
use MusicRadar\Taste\ProfileBuilder;
use MusicRadar\Taste\RatingInfluence;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " - $detail") . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

$artist = static fn (string $id, string $name, ?string $country, array $tags, string $disamb = '', int $score = 100) => [
    'id' => $id, 'name' => $name, 'country' => $country, 'disambiguation' => $disamb, 'score' => $score,
    'tags' => array_map(static fn ($t) => ['name' => $t, 'count' => 1], $tags),
];
$profile = ['Hardcore', 'Pop', 'Hip hop', 'Electronic'];

echo "Speed - three artists share the name, Last.fm tags are mixed\n";
$m = ArtistMatcher::match('Speed', '', ['Pop', 'Hardcore', 'Electronic'], ['JP'], true, [
    $artist('dee1f6b1-4459-49c5-aef3-0f98d15c122f', 'SPEED', 'JP', ['j-pop', 'girl group', 'dance-pop'], 'Japanese group'),
    $artist('36c785b6-4ff1-443e-941d-26106932d6d5', 'SPEED', 'KR', ['boy group'], 'South Korean boy group', 87),
    $artist('4ccddb42-9be6-46d8-822c-d9974962abec', 'SPEED', null, ['punk', 'metalcore', 'hardcore punk'], 'Hardcore band from Australia', 87),
    $artist('6c02ba0f-c69c-4145-9ffb-6fca9163bd89', 'Ronski Speed', 'DE', ['trance'], '', 94),
], null, $profile);
check('needs review', $m->status === ArtistMatch::NEEDS_REVIEW, $m->status);
check('no MBID chosen', $m->mbid === null);
check('3 same-name candidates listed', count($m->candidates) === 3, (string) count($m->candidates));
check('Australian hardcore band ranked first (fits profile)', ($m->candidates[0]['disambiguation'] ?? '') === 'Hardcore band from Australia', json_encode($m->candidates[0]));

echo "SYL - Last.fm's MBID points to Strapping Young Lad\n";
$m = ArtistMatcher::match('SYL', '584b72a4-45f7-46ce-b440-e9ee579d71c4', [], [], false, [
    $artist('2e8cbd3d-44c0-4725-89e0-7db8010aa832', 'Syl Johnson', 'US', ['blues', 'soul']),
    $artist('72481187-d9fe-4091-928d-0599af00e457', 'SYL', 'DK', ['punk'], 'Copenhagen based Punk Band', 85),
], ['id' => '584b72a4-45f7-46ce-b440-e9ee579d71c4', 'name' => 'Strapping Young Lad', 'country' => 'CA', 'tags' => [['name' => 'industrial metal']]], $profile);
check('needs review', $m->status === ArtistMatch::NEEDS_REVIEW, $m->status);
check('Strapping Young Lad not chosen', $m->mbid === null);
check('problem mentions Strapping Young Lad', str_contains(implode(' ', $m->problems), 'Strapping Young Lad'));
check('Danish punk band offered as candidate', ($m->candidates[0]['name'] ?? '') === 'SYL');

echo "SYL - Strapping Young Lad has 'SYL' as an alias AND Last.fm points to it (real-world case)\n";
$sly = $artist('584b72a4-45f7-46ce-b440-e9ee579d71c4', 'Strapping Young Lad', 'CA', ['industrial metal', 'death metal']);
$sly['aliases'] = [['name' => 'SYL']];
$m = ArtistMatcher::match('SYL', '584b72a4-45f7-46ce-b440-e9ee579d71c4', [], [], false, [
    $artist('72481187-d9fe-4091-928d-0599af00e457', 'SYL', 'DK', ['punk'], 'Copenhagen based Punk Band', 85),
], $sly, $profile);
check('NOT confirmed as Strapping Young Lad', $m->mbid !== '584b72a4-45f7-46ce-b440-e9ee579d71c4', (string) $m->mbid);
check('needs review', $m->status === ArtistMatch::NEEDS_REVIEW, $m->status);
check('alias explained', str_contains(implode(' ', $m->problems), 'alias'), implode(' | ', $m->problems));

echo "Only an alias matches - never automatic\n";
$alias = $artist('55555555-5555-5555-5555-555555555555', 'Full Band Name', 'US', ['hardcore']);
$alias['aliases'] = [['name' => 'FBN']];
$m = ArtistMatcher::match('FBN', '', ['Hardcore'], ['US'], false, [$alias], null);
check('needs review', $m->status === ArtistMatch::NEEDS_REVIEW, $m->status);

echo "Kim Larsen - two Danish artists, only one has matching genres\n";
$m = ArtistMatcher::match('Kim Larsen', '', ['Rock & indie', 'Pop'], ['DK'], false, [
    $artist('0a648853-66bb-4b18-bd52-0315b14fbb82', 'Kim Larsen', 'DK', ['rock', 'danish'], 'Danish rock musician'),
    $artist('6b71dc4e-d4e2-4bfc-a004-8298582cfa7b', 'Kim Larsen', 'DK', [], 'Of the Wand & the Moon singer'),
], null, $profile);
check('confirmed: the rock musician', $m->status === ArtistMatch::CONFIRMED && $m->mbid === '0a648853-66bb-4b18-bd52-0315b14fbb82', $m->status);

echo "Drain - another same-name artist has related genres -> stay careful\n";
$m = ArtistMatcher::match('Drain', '', ['Hardcore', 'Metal'], [], false, [
    $artist('9b0a6218-d0ad-4131-8c80-53b8f78913ee', 'DRAIN', null, ['hardcore punk', 'crossover thrash'], 'Santa Cruz hardcore band'),
    $artist('2d970f40-cd00-44c1-9fc8-0721da8048e2', 'Drain', 'SE', ['rock']),
], null, $profile);
check('needs review', $m->status === ArtistMatch::NEEDS_REVIEW, $m->status);
check('Santa Cruz band suggested first', ($m->candidates[0]['mbid'] ?? '') === '9b0a6218-d0ad-4131-8c80-53b8f78913ee');

echo "Vilma Crow - unique name, only a 'danish' tag on Last.fm\n";
$m = ArtistMatcher::match('Vilma Crow', '', [], ['DK'], false, [
    $artist('38c55a73-c387-4690-94d1-55888893dd47', 'Vilma Crow', 'DK', []),
], null, $profile);
check('confirmed', $m->status === ArtistMatch::CONFIRMED, $m->status);
check('medium confidence', $m->confidence === 'medium', $m->confidence);

echo "Knocked Loose - everything agrees\n";
$m = ArtistMatcher::match('Knocked Loose', '9ca10859-49e2-44e3-b3d6-04c535207bc2', ['Hardcore', 'Metalcore'], ['US'], false, [
    $artist('9ca10859-49e2-44e3-b3d6-04c535207bc2', 'Knocked Loose', 'US', ['hardcore', 'metalcore']),
], null, $profile);
check('confirmed with high confidence', $m->status === ArtistMatch::CONFIRMED && $m->confidence === 'high', "$m->status/$m->confidence");

echo "Unique name, but genres contradict\n";
$m = ArtistMatcher::match('Drain', '', ['Hardcore'], [], false, [
    $artist('11111111-1111-1111-1111-111111111111', 'Drain', 'NO', ['jazz', 'free improvisation']),
], null, $profile);
check('needs review', $m->status === ArtistMatch::NEEDS_REVIEW, $m->status);

echo "Related genres are not a contradiction (Last.fm hardcore vs MusicBrainz punk)\n";
$m = ArtistMatcher::match('SYL', '', ['Hardcore'], ['DK'], false, [
    $artist('72481187-d9fe-4091-928d-0599af00e457', 'SYL', 'DK', ['punk']),
], null, $profile);
check('confirmed', $m->status === ArtistMatch::CONFIRMED, $m->status . ' ' . implode('; ', $m->problems));

echo "Only the name matches, nothing else known\n";
$m = ArtistMatcher::match('Six Sex', '', [], [], false, [$artist('22222222-2222-2222-2222-222222222222', 'Six Sex', null, [])], null);
check('needs review (not auto-accepted)', $m->status === ArtistMatch::NEEDS_REVIEW, $m->status);

echo "Two same-name artists, Last.fm MBID + genres single out one\n";
$m = ArtistMatcher::match('Eyes', '33333333-3333-3333-3333-333333333333', ['Hardcore', 'Metalcore'], ['DK'], false, [
    $artist('33333333-3333-3333-3333-333333333333', 'Eyes', 'DK', ['hardcore', 'noise rock']),
    $artist('44444444-4444-4444-4444-444444444444', 'Eyes', 'US', ['jazz']),
], null, $profile);
check('confirmed', $m->status === ArtistMatch::CONFIRMED && $m->mbid === '33333333-3333-3333-3333-333333333333', $m->status);

echo "Nothing on MusicBrainz\n";
$m = ArtistMatcher::match('Nobody Here', '', ['Hardcore'], [], false, [], null);
check('not found', $m->status === ArtistMatch::NOT_FOUND, $m->status);

echo "Name normalisation\n";
check('Æ/Ø/Å and case', ArtistMatcher::normalize('KVÆLNING') === ArtistMatcher::normalize('kvaelning'));
check('"The" prefix and punctuation', ArtistMatcher::normalize('The Floor Is Made of Lava') === ArtistMatcher::normalize('Floor is made of lava!'));
check('non-Latin names survive', ArtistMatcher::normalize('鳳') !== '');

echo "Watch levels: following / interested\n";
$w = FollowPolicy::decide(['3month' => ['rank' => 23, 'plays' => 43]]);
check('new interest (e.g. Initiate) is watched as interested', $w['level'] === FollowPolicy::INTERESTED, $w['level']);
$w = FollowPolicy::decide(['overall' => ['rank' => 29, 'plays' => 841]]);
check('quiet old favourite stays interested', $w['level'] === FollowPolicy::INTERESTED, $w['level']);
check('nothing becomes "following" automatically', FollowPolicy::decide(['overall' => ['rank' => 1, 'plays' => 2500], '12month' => ['rank' => 1, 'plays' => 300], '3month' => ['rank' => 1, 'plays' => 100]])['level'] === FollowPolicy::INTERESTED);
check('strong artist is suggested for following', FollowPolicy::decide(['overall' => ['rank' => 2, 'plays' => 2321]])['suggest_following'] !== null);
check('passing interest is not suggested', FollowPolicy::decide(['3month' => ['rank' => 27, 'plays' => 38]])['suggest_following'] === null);
check('manual "following" wins', FollowPolicy::decide(['3month' => ['rank' => 27, 'plays' => 38]], 'following')['level'] === FollowPolicy::FOLLOWING);
check('manual "ignored" wins', FollowPolicy::decide(['overall' => ['rank' => 1, 'plays' => 2500]], 'ignored')['level'] === FollowPolicy::IGNORED);
check('retained artist stays interested', FollowPolicy::decide([], null, true)['source'] === 'retained');
$threw = false;
try {
    FollowPolicy::decide([], 'favourite');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('typo in level is rejected', $threw);

echo "Nothing disappears automatically\n";
$current = ['Turnstile' => ['name' => 'Turnstile', 'periods' => ['3month' => ['rank' => 2, 'plays' => 114]], 'lastfm_mbid' => '', 'listening' => 'current', 'retained' => false]];
$previous = ['artists' => [
    ['name' => 'Turnstile', 'lastfm' => ['mbid_hint' => null]],
    ['name' => 'Green Day', 'lastfm' => ['mbid_hint' => '084308bd-1654-436f-ba03-df6697104e19']],
]];
$merged = ProfileBuilder::retain($current, $previous, ['Converge' => ['level' => 'following'], 'Some Band' => ['level' => 'ignored']]);
check('artist that dropped out of Last.fm is kept', isset($merged['Green Day']) && $merged['Green Day']['retained'] === true);
check('kept artist keeps its MBID hint', ($merged['Green Day']['lastfm_mbid'] ?? '') === '084308bd-1654-436f-ba03-df6697104e19');
check('current artist untouched', $merged['Turnstile']['retained'] === false && $merged['Turnstile']['periods'] !== []);
check('manually followed artist is added', isset($merged['Converge']));
check('ignored-only artist is not added', !isset($merged['Some Band']));

echo "Top 100 profile: every top-100 artist is Interested and part of the taste\n";
check('#100 in a period is Interested', FollowPolicy::decide(['3month' => ['rank' => 100, 'plays' => 5]])['level'] === FollowPolicy::INTERESTED);

// A whole build with simulated Last.fm and MusicBrainz: 100 artists per period.
$mbid = static fn (int $n) => sprintf('00000000-0000-0000-0000-%012d', $n);
$searches = [];
$lfm = new MusicRadar\LastFm\LastFmClient(str_repeat('ab', 16), 10, static function (string $url) use ($mbid): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    if ($q['method'] === 'user.getTopArtists') {
        $n = $q['period'] === 'overall' ? 100 : 10; // the recent lists: the same top 10
        return [200, json_encode(['topartists' => ['artist' => array_map(static fn ($i) => ['name' => "Band $i", 'playcount' => 1000 - $i, 'mbid' => $mbid($i)], range(1, $n))]]), ''];
    }
    return [200, json_encode(['toptags' => ['tag' => [['name' => 'hardcore', 'count' => 100]]]]), ''];
});
$mb = new MusicRadar\MusicBrainz\MusicBrainzClient(null, 0, 15, 1, static function (string $url) use ($mbid, &$searches): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    preg_match('/Band (\d+)/', $q['query'] ?? '', $m);
    $searches[] = $m[1] ?? '?';
    return [200, json_encode(['artists' => isset($m[1]) ? [['id' => $mbid((int) $m[1]), 'name' => "Band {$m[1]}", 'score' => 100, 'tags' => [['name' => 'hardcore', 'count' => 1]]]] : []]), ''];
});
$prev = (new ProfileBuilder($lfm, $mb))->build('test');
$byName = array_column($prev['artists'], null, 'name');
check('build: 100 profile artists', count($prev['artists']) === 100, (string) count($prev['artists']));
check('...all 100 Interested', $prev['watch_summary'] === ['interested' => 100], json_encode($prev['watch_summary']));
check('...#100 is watched for releases like #1', KnownArtistReleases::watched($byName['Band 100']) && KnownArtistReleases::watched($byName['Band 1']));
check('...and makes a release Interested', ((new MusicRadar\Recommend\ArtistInterest($prev))->watch([['mbid' => $mbid(100)]])['level'] ?? null) === 'interested');
$taste = MusicRadar\Recommend\TasteModel::fromProfile($prev, require __DIR__ . '/../config/recommender.php');
check('...and all 100 form the musical taste profile', $prev['sources']['lastfm']['taste_artists'] === 100
    && array_column($taste->artists, 'name') === array_map(static fn ($i) => "Band $i", range(1, 100)) && $byName['Band 100']['lastfm']['taste']);
check('...each artist once, whatever the number of periods it is in', count($taste->artists) === count(array_unique(array_column($taste->artists, 'name'))));
$layer = static fn (array $p, string $name) => array_column($p['artists'], null, 'name')[$name]['lastfm']['listening'];
check('...a top-10 artist in every period is "core", one only in the all-time list "dormant"', $layer($prev, 'Band 5') === 'core'
    && $layer($prev, 'Band 50') === 'dormant');
$moved = (new ProfileBuilder($lfm, $mb, [], null, ['artists' => [['name' => 'Band 140', 'lastfm' => ['mbid_hint' => null]]]]))->build('test');
check('an artist that was in the taste profile and dropped out of the top 100 stays in it (kept, as before)', array_column($moved['artists'], null, 'name')['Band 140']['lastfm']['taste']);
$searches = [];
$again = (new ProfileBuilder($lfm, $mb, [], null, $prev))->build('test');
check('weekly rebuild: confirmed MusicBrainz matches are reused - no searches', $searches === [] && $again['sources']['musicbrainz_reused'] === 100,
    count($searches) . ' searches');
check('...and the same artists, levels and ids', array_column($again['artists'], 'watch', 'name') == array_column($prev['artists'], 'watch', 'name')
    && array_column(array_column($again['artists'], 'musicbrainz'), 'mbid') === array_column(array_column($prev['artists'], 'musicbrainz'), 'mbid'));

echo "Play weights: the most played count most, #95 still counts\n";
$w = ProfileBuilder::playWeights([
    'Top' => ['periods' => ['overall' => ['rank' => 1, 'plays' => 400], '3month' => ['rank' => 1, 'plays' => 100]]],
    'Quarter' => ['periods' => ['overall' => ['rank' => 50, 'plays' => 100]]],
    'Recent' => ['periods' => ['overall' => ['rank' => 90, 'plays' => 25], '3month' => ['rank' => 2, 'plays' => 64]]],
    'Kept' => ['periods' => []],
]);
check('your #1 counts fully', $w['Top'] === 1.0);
check('damped: a quarter of the plays counts half (square root)', abs($w['Quarter'] - 0.5) < 1e-9, json_encode($w));
check('an artist in several periods counts once, with its best period (recent listening counts)', abs($w['Recent'] - 0.8) < 1e-9, json_encode($w));
check('an artist kept from an earlier profile gets the lowest weight of the ranked ones', $w['Kept'] === $w['Quarter'], json_encode($w));
$entry = static fn (string $tag, float $weight) => ['lastfm' => ['listening' => 'core', 'play_weight' => $weight],
    'genres' => ['weights' => [$tag => 1.0], 'families' => []]];
$g = (new ReflectionMethod(ProfileBuilder::class, 'genreProfile'))->invoke(null, [$entry('hardcore', 1.0), $entry('techno', 0.5)], 'genres');
$g = array_column($g, 'weight', 'name');
check('the genre profile weighs artists by their play weight (2:1 here), within a listening layer', abs($g['hardcore'] - 2 * $g['techno']) < 0.001, json_encode($g));

echo "Manual overrides file\n";
$ov = require __DIR__ . '/../data/artist-overrides.php';
$validMbid = static fn ($m) => $m === false || (is_string($m) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $m));
$bad = array_keys(array_filter($ov, static fn ($o) => (array_key_exists('mbid', $o) && !$validMbid($o['mbid']))
    || (isset($o['level']) && !in_array($o['level'], FollowPolicy::LEVELS, true))));
check('all overrides are well-formed', $bad === [], implode(', ', $bad));
check('SYL is never Strapping Young Lad', ($ov['SYL']['mbid'] ?? '') !== '584b72a4-45f7-46ce-b440-e9ee579d71c4');
check('Eyes is the Copenhagen hardcore band', ($ov['Eyes']['mbid'] ?? '') === 'bf5bf232-32c7-4ee6-ba96-da62a1d70419');

echo "Rating influence\n";
check('no ratings = no influence', RatingInfluence::share(0) === 0.0);
check('one rating is a small nudge', RatingInfluence::share(1) < 0.05, (string) RatingInfluence::share(1));
check('a single 1-star rating does not flip a genre', RatingInfluence::genreSignal([1]) > -0.2, (string) RatingInfluence::genreSignal([1]));
check('consistent 5-star ratings move it clearly', RatingInfluence::genreSignal([5, 5, 5, 5, 5, 5, 5, 5, 5, 5]) > 0.6);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
