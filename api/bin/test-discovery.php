<?php

declare(strict_types=1);

/**
 * Offline tests for the discovery experiment and the rule-based
 * recommendation engine (no network).
 *
 *   docker compose exec php php /var/www/api/bin/test-discovery.php
 */

use MusicRadar\Discovery\CandidateFinder;
use MusicRadar\Discovery\ProfileSummary;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\Recommend\RecommendationEngine;
use MusicRadar\Recommend\SimilarArtists;
use MusicRadar\Recommend\TagInformation;
use MusicRadar\Recommend\TasteModel;
use MusicRadar\Taste\TagClassifier;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../bootstrap.php';
$config = require __DIR__ . '/../config/recommender.php';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " - $detail") . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

// --- A small, realistic profile ---------------------------------------------
$artist = static fn (string $name, string $layer, array $weights, array $families, ?string $country = 'US') => [
    'name' => $name, 'country' => $country,
    'lastfm' => ['listening' => $layer, 'periods' => ['overall' => ['rank' => 1, 'plays' => 2000]]],
    'genres' => ['weights' => $weights, 'families' => $families],
    'musicbrainz' => ['mbid' => md5($name)], 'watch' => ['level' => 'interested'],
];
$profile = [
    'user' => 'test',
    'genres' => [
        ['name' => 'hardcore', 'weight' => 0.15], ['name' => 'metalcore', 'weight' => 0.06], ['name' => 'beatdown hardcore', 'weight' => 0.03],
        ['name' => 'hip hop', 'weight' => 0.08], ['name' => 'rap', 'weight' => 0.04], ['name' => 'pop', 'weight' => 0.09],
        ['name' => 'techno', 'weight' => 0.03], ['name' => 'punk rock', 'weight' => 0.035], ['name' => 'indie pop', 'weight' => 0.04],
    ],
    'families' => [
        ['name' => 'Hardcore', 'weight' => 0.24], ['name' => 'Pop', 'weight' => 0.17], ['name' => 'Hip hop', 'weight' => 0.15],
        ['name' => 'Punk', 'weight' => 0.08], ['name' => 'Metalcore', 'weight' => 0.07], ['name' => 'Electronic', 'weight' => 0.07],
        ['name' => 'Rock & indie', 'weight' => 0.10], ['name' => 'Metal', 'weight' => 0.05],
    ],
    'attributes' => ['danish_share' => 0.17],
    'artists' => [
        $artist('Knocked Loose', 'core', ['hardcore' => 0.4, 'metalcore' => 0.36, 'beatdown hardcore' => 0.16, 'metallic hardcore' => 0.08], ['Hardcore' => 0.64, 'Metalcore' => 0.36]),
        $artist('Initiate', 'current', ['hardcore' => 0.6, 'hardcore punk' => 0.4], ['Hardcore' => 1.0]),
        $artist('Per Vers', 'current', ['rap' => 0.5, 'hip hop' => 0.5], ['Hip hop' => 1.0], 'DK'),
        $artist('Sara Landry', 'current', ['minimal techno' => 0.5, 'techno' => 0.5], ['Electronic' => 1.0]),
        $artist('Chat Pile', 'current', ['noise rock' => 0.4, 'sludge metal' => 0.3, 'post-hardcore' => 0.3], ['Rock & indie' => 0.4, 'Metal' => 0.3, 'Post-hardcore & emo' => 0.3]),
    ],
];
$taste = TasteModel::fromProfile($profile, $config);

$release = static fn (string $id, array $facts, array $extra = []) => $extra + [
    'id' => $id, 'title' => "Title $id", 'type' => 'Album', 'first_release_date' => '2026-10-10',
    'artist' => ['name' => "Artist $id", 'mbid' => "mbid-$id", 'primary_name' => "Artist $id", 'credited_artists' => 1],
    'facts' => array_map(static fn ($f, $i) => ['id' => 'F' . ($i + 1), 'text' => $f[0], 'source' => $f[1]], $facts, array_keys($facts)),
    'source' => 'random', 'metadata_quality' => 'good', 'country' => null, 'issues' => [],
];

$releases = [
    'hc' => $release('hc', [
        ['Album "X" by Y, first released 2026-10-10', 'musicbrainz:release-group'],
        ['Artist genres/tags: metallic hardcore, beatdown hardcore, hardcore', 'musicbrainz:artist'],
        ['Artist tags on Last.fm (strongest first): hardcore, metalcore', 'lastfm:artist'],
    ]),
    'classical' => $release('classical', [
        ['Album "C" by D, first released 2026-10-10', 'musicbrainz:release-group'],
        ['Artist genres/tags: classical, orchestral, baroque', 'musicbrainz:artist'],
    ]),
    'noise' => $release('noise', [
        ['Album "N" by M, first released 2026-10-10', 'musicbrainz:release-group'],
        ['Artist Noisy: Group, from Copenhagen, active since 2025', 'musicbrainz:artist'],
        ['Artist genres/tags: noise rock, sludge metal, hardcore', 'musicbrainz:artist'],
    ], ['country' => 'DK']),
    'empty' => $release('empty', [['Album "E" by F, first released 2026-10-10', 'musicbrainz:release-group']], ['metadata_quality' => 'insufficient']),
    'follow' => $release('follow', [
        ['Album "Z" by W, first released 2026-10-10', 'musicbrainz:release-group'],
        ['Artist genres/tags: country, bluegrass', 'musicbrainz:artist'],
    ], ['watch_level' => 'following']),
    'thin' => $release('thin', [
        ['Album "T" by T, first released 2026-10-10', 'musicbrainz:release-group'],
        ['Release tags: hardcore', 'musicbrainz:release-group'],
    ], ['metadata_quality' => 'partial']),
];

$engine = new RecommendationEngine($taste, $config);
$res = $engine->run($releases);
$rec = array_column($res['recommended'], null, 'id');
$wild = array_column($res['wildcards'], null, 'id');
$as = $res['assessments'];

echo "Recommended for You\n";
check('hardcore release fitting the profile is recommended', isset($rec['hc']), json_encode($as['hc']['fit']));
check('it names concrete neighbour artists', str_contains($rec['hc']['why'] ?? '', 'Knocked Loose') || str_contains(json_encode($rec['hc']['reasons'] ?? []), 'Knocked Loose'));
check('specific genres explain the pick', str_contains(json_encode($rec['hc']['reasons'] ?? []), 'beatdown hardcore'));
$allFactIds = static fn ($pick) => array_merge(...array_column($pick['reasons'], 'facts'));
check('reasons only cite facts that exist', array_diff($allFactIds($rec['hc']), array_column($releases['hc']['facts'], 'id')) === []);
check('following artist always recommended, even outside your taste', isset($rec['follow']) && $rec['follow']['forced']);
check('classical (unrelated) is not recommended', !isset($rec['classical']));
check('classical is not a wildcard either (no bridge)', !isset($wild['classical']));
check('classical counts as outside your taste', $as['classical']['signals']['outside'] > 0.5, json_encode($as['classical']['signals']));

echo "Wildcards\n";
check('noise rock with a hardcore bridge becomes a wildcard', isset($wild['noise']) || isset($rec['noise']), $as['noise']['decision'] . ' ' . json_encode($as['noise']['signals']));
if (isset($wild['noise'])) {
    check('wildcard leads with what is new', str_contains($wild['noise']['why'], 'New territory') || str_contains($wild['noise']['why'], 'Combines'));
    check('wildcard has an angle', in_array($wild['noise']['angle'], ['genre-blend', 'new-band', 'neighbouring-genre', 'left-field'], true));
}
check('a Danish artist gets no bonus and no Danish reason (rule switched off)', !str_contains(json_encode($as['noise']['reasons']), 'Danish artist')
    && ($as['noise']['score_parts']['danish'] ?? null) == 0, json_encode($as['noise']['score_parts'] ?? null));
check('new artist recognised from start year', str_contains(json_encode($as['noise']['reasons']), 'New artist (active since 2025)'));

echo "Honesty about data\n";
check('release without genre data is not assessed', $as['empty']['decision'] === 'not_assessable');
check('thin data lowers confidence', $as['thin']['confidence'] < $as['hc']['confidence']);
check('no score shown as a percentage in reasons', !preg_match('/\d+ ?%/', json_encode(array_column($res['recommended'], 'why'))));

echo "No fixed limit, known artists, limited data\n";
$many = [];
for ($i = 0; $i < 20; $i++) {
    $many["h$i"] = $release("h$i", [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore, metallic hardcore, beatdown hardcore, metalcore', 'musicbrainz:artist']]);
}
$many['int'] = $release('int', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: country, bluegrass', 'musicbrainz:artist']], ['watch_level' => 'interested']);
$many['bare'] = $release('bare', [['Album', 'musicbrainz:release-group']], ['watch_level' => 'interested', 'metadata_quality' => 'insufficient']);
$many['thinstrong'] = $release('thinstrong', [['Album', 'musicbrainz:release-group'], ['Release tags: hardcore, hardcore punk', 'musicbrainz:release-group']], ['metadata_quality' => 'partial']);
$r2 = (new RecommendationEngine($taste, $config))->run($many);
$rec2 = array_column($r2['recommended'], null, 'id');
check('no top-15 cut: all 20 fitting releases are recommended', count(array_filter(array_keys($rec2), static fn ($id) => $id[0] === 'h')) === 20, (string) count($rec2));
check('interested artist always included, even outside your taste', ($rec2['int']['origin'] ?? null) === 'interested');
check('...also without any genre data, marked limited data', ($rec2['bare']['origin'] ?? null) === 'interested' && $rec2['bare']['data_quality'] === 'limited');
check('following is marked as origin', ($rec['follow']['origin'] ?? null) === 'following');
check('new finds are marked as discovery', ($rec['hc']['origin'] ?? null) === 'discovery');
check('thin data is not recommended on genre tags alone, however well one tag fits', !isset($rec2['thinstrong']) && !isset($rec['thin']),
    json_encode([$r2['assessments']['thinstrong']['fit'], $r2['assessments']['thinstrong']['confidence']]));
// A concrete reason beyond the tags - a Last.fm link to an artist you play - makes it a cautious bet.
$linked = ['similar' => ['identity' => 'mbid', 'links' => ['Knocked Loose' => ['s' => 0.4, 'out' => 0.4, 'in' => 0.0]]]] + $many['thinstrong'];
$rl = (new RecommendationEngine($taste, $config))->run(['tl' => ['id' => 'tl'] + $linked, 't0' => ['id' => 't0'] + $many['thinstrong']]);
$wl = array_column($rl['wildcards'], null, 'id');
check('...with a Last.fm link to an artist you play: a "limited data" wildcard', ($wl['tl']['angle'] ?? null) === 'limited-data', $rl['assessments']['tl']['decision']);
check('...that leads with the link and says what we don\'t know', ($wl['tl']['reasons'][0]['kind'] ?? null) === 'lastfm'
    && str_contains(json_encode($wl['tl']['reasons'] ?? []), 'Limited music data'), json_encode(array_column($wl['tl']['reasons'] ?? [], 'text')));
check('...and thin data alone is never a wildcard', !isset($wl['t0']) && $rl['recommended'] === []);
check('no invented text for limited data', !str_contains(json_encode($rec2['bare']), 'genres you listen to'));

echo "Known artists are explained from your listening history
";
$known = [
    'sl' => $release('sl', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: techno, minimal techno, hard techno', 'musicbrainz:artist']],
        ['watch_level' => 'interested', 'artist' => ['name' => 'Sara Landry', 'mbid' => md5('Sara Landry'), 'primary_name' => 'Sara Landry', 'credited_artists' => 1]]),
    'ini' => $release('ini', [['Album', 'musicbrainz:release-group']],
        ['watch_level' => 'interested', 'metadata_quality' => 'insufficient', 'artist' => ['name' => 'Initiate', 'mbid' => md5('Initiate'), 'primary_name' => 'Initiate', 'credited_artists' => 1]]),
    'kl' => $release('kl', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore, metalcore', 'musicbrainz:artist']],
        ['watch_level' => 'following', 'artist' => ['name' => 'Knocked Loose', 'mbid' => md5('Knocked Loose'), 'primary_name' => 'Knocked Loose', 'credited_artists' => 1]]),
];
$r6 = (new RecommendationEngine($taste, $config))->run($known);
$k6 = array_column($r6['recommended'], null, 'id');
$texts = static fn ($id) => array_column($k6[$id]['reasons'] ?? [], 'text');
check('known artist: natural reason from the listening layer', ($texts('sl')[0] ?? '') === 'New release from Sara Landry, one of the artists you play most right now on Last.fm', $texts('sl')[0] ?? '-');
check('an artist is never "close to" itself', !str_contains(implode(' ', $texts('sl')), 'close to Sara Landry'), implode(' | ', $texts('sl')));
check('...without changing the score', $r6['assessments']['sl']['signals']['neighbours'] > 0 && $r6['assessments']['sl']['fit'] === (new RecommendationEngine($taste, $config))->assess($known['sl'])['fit']);
check('interested without genre data still gets a real reason', ($texts('ini')[0] ?? '') === 'New release from Initiate, one of the artists you play most right now on Last.fm', $texts('ini')[0] ?? '-');
check('...and is marked limited data, nothing invented', $k6['ini']['data_quality'] === 'limited' && count($texts('ini')) === 2, implode(' | ', $texts('ini')));
check('following artist reason', ($texts('kl')[0] ?? '') === 'You follow Knocked Loose - new releases are always included', $texts('kl')[0] ?? '-');
check('artist outside the profile: generic but true wording', str_contains($texts('int')[0] ?? implode('', array_column($rec2['int']['reasons'], 'text')), 'an artist in your Last.fm listening history'));
$ex = (new RecommendationEngine($taste, $config))->explain($known['sl'], 'recommended', 'interested');
check('explain() gives the same reasons and score as run()', $ex['reasons'] === $k6['sl']['reasons'] && $ex['fit'] === $k6['sl']['fit']);

echo "Variation (ordering, no quotas)\n";
$mix = [];
for ($i = 0; $i < 6; $i++) {
    $mix["k$i"] = $release("k$i", [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore, metalcore, beatdown hardcore, metallic hardcore', 'musicbrainz:artist']]);
}
// A different kind of match (close to Initiate, not Knocked Loose): enough genre data and a Last.fm link.
$mix['rap'] = $release('rap', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore punk, hardcore, punk rock, melodic hardcore', 'musicbrainz:artist']],
    ['similar' => ['identity' => 'mbid', 'links' => ['Initiate' => ['s' => 0.5, 'out' => 0.5, 'in' => 0.0]]]]);
$r4 = (new RecommendationEngine($taste, $config))->run($mix);
$order = array_column($r4['recommended'], 'id');
$pos = array_search('rap', $order, true);
check('a different kind of match is not pushed to the bottom by near-identical ones', $pos !== false && $pos < 6, implode(',', $order) . ' fits ' . json_encode(array_column($r4['recommended'], 'fit', 'id')));
check('all near-identical releases are still listed', count(array_filter($order, static fn ($id) => $id[0] === 'k')) === 6);

echo "Wildcards are real bets\n";
$bets = [
    'mc' => $release('mc', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: metalcore, hardcore, melodic metalcore', 'musicbrainz:artist']]),
    'hctech' => $release('hctech', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore, techno, industrial techno, beatdown hardcore', 'musicbrainz:artist']]),
];
$r5 = (new RecommendationEngine($taste, $config))->run($bets);
$w5 = array_column($r5['wildcards'], null, 'id');
$a5 = $r5['assessments'];
check('ordinary metalcore/hardcore is never a wildcard', !isset($w5['mc']), $a5['mc']['decision']);
check('hardcore + techno (unrelated families, a mix none of your artists has) is a wildcard or a plain match',
    isset($w5['hctech']) || $a5['hctech']['fit'] >= $config['wildcards']['max_fit'], $a5['hctech']['decision'] . ' ' . json_encode([$a5['hctech']['fit'], $a5['hctech']['unrelated_blend']]));
check('every wildcard has a specific bridge', array_filter($r5['wildcards'], static fn ($w) => $w['signals']['neighbours'] < $config['wildcards']['min_neighbour_bridge'] && $a5[$w['id']]['specific_shared'] === []) === []);
$eightBets = [];
for ($i = 0; $i < 8; $i++) {
    $eightBets["w$i"] = ['id' => "w$i"] + $bets['hctech'];
}
$r7 = (new RecommendationEngine($taste, $config))->run($eightBets);
check('no cap or family quota on wildcards: every real bet is one', count($r7['wildcards']) === count(array_filter($r7['assessments'], static fn ($a) => $a['decision'] === 'wildcard'))
    && (count($r7['wildcards']) === 8 || $r7['assessments']['w0']['decision'] !== 'wildcard'), json_encode(array_count_values(array_column($r7['assessments'], 'decision'))));

echo "Not interested\n";
$twin = $release('hc2', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: metallic hardcore, beatdown hardcore, hardcore', 'musicbrainz:artist'],
    ['Artist tags on Last.fm (strongest first): hardcore, metalcore', 'lastfm:artist']], ['artist' => $releases['hc']['artist']]);
$ni = new RecommendationEngine($taste, $config, ['not_interested_releases' => ['hc']]);
$r3 = $ni->run($releases + ['hc2' => $twin]);
check('"Not interested" release is hidden', !in_array('hc', array_column($r3['recommended'], 'id'), true) && $r3['assessments']['hc']['decision'] === 'excluded');
check('...but not the artist\'s other release', in_array('hc2', array_column($r3['recommended'], 'id'), true));
// As FeedbackSet::forEngine() passes it: also a 1-star rating of the artist, without tags.
$niArtist = new RecommendationEngine($taste, $config, ['not_interested_releases' => ['hc'], 'ratings' => [
    ['release_id' => 'hc', 'artist_mbid' => 'mbid-hc', 'artist' => 'Artist hc', 'title' => 'Title hc', 'tags' => [], 'stars' => 1, 'not_interested' => true]]]);
$plainEngine = new RecommendationEngine($taste, $config);
check('...it counts against the other releases by the artist', $niArtist->assess($twin)['fit'] < $plainEngine->assess($twin)['fit']);
check('...but not against the genre (similar release, other artist)', $niArtist->assess($many['h0'])['fit'] === $plainEngine->assess($many['h0'])['fit']);
check('...the hidden release keeps its own score', $niArtist->run($releases)['assessments']['hc']['fit'] === $as['hc']['fit']);
check('...and the reason says Not interested, not a star rating', str_contains(json_encode($niArtist->assess($twin)), 'You marked Title hc by Artist hc Not interested'));
$knownNi = (new RecommendationEngine($taste, $config, ['not_interested_releases' => ['follow']]))->run($releases);
check('...also hides a release from an artist you follow', !in_array('follow', array_column($knownNi['recommended'], 'id'), true));

echo "Ratings: learning gradually\n";
$fitWith = static fn (array $ratings, array $r) => (new RecommendationEngine($taste, $config, ['ratings' => $ratings]))->assess($r)['fit'];
$rated = static fn (string $id, array $tags, int $stars, ?string $artistMbid = null) => ['release_id' => $id, 'artist_mbid' => $artistMbid ?? "mbid-$id", 'artist' => "Artist $id", 'title' => "Title $id", 'tags' => $tags, 'stars' => $stars];
$base = $as['hc']['fit'];
$hcTags = ['metallic hardcore', 'beatdown hardcore', 'hardcore', 'metalcore'];
check('no ratings: engine behaves exactly as before', $fitWith([], $releases['hc']) === $base);
$byStars = array_map(static fn ($s) => $fitWith([$rated('x', $hcTags, $s)], $releases['hc']), [1, 2, 3, 4, 5]);
check('1-5 stars move a similar release in order', $byStars === array_values(array_unique($byStars)) && $byStars == array_values((static function ($a) { sort($a); return $a; })($byStars)), json_encode($byStars));
check('3 stars are (almost) neutral', abs($byStars[2] - $base) < 0.02, (string) ($byStars[2] - $base));
check('one 5-star rating is a nudge, not a takeover', $byStars[4] - $base > 0 && $byStars[4] - $base < 0.1, (string) ($byStars[4] - $base));
check('one 1-star rating is a nudge, not a verdict', $base - $byStars[0] > 0 && $base - $byStars[0] < 0.1, (string) ($base - $byStars[0]));
$many1 = $fitWith(array_map(static fn ($i) => $rated("n$i", $hcTags, 1), range(1, 15)), $releases['hc']);
check('many consistent 1-star ratings move it clearly', $base - $many1 > 0.15, (string) ($base - $many1));
$many5 = $fitWith(array_map(static fn ($i) => $rated("p$i", $hcTags, 5), range(1, 15)), $releases['hc']);
check('...and can outweigh the Last.fm profile over time', $many5 - $base > 0.25, (string) ($many5 - $base));
$maxed = $fitWith(array_map(static fn ($i) => $rated("same$i", $hcTags, 5, 'mbid-hc'), range(1, 20)), $releases['hc']);
check('many 5-star ratings of the artist keep the score below 1 (1 = strongest possible match)', $maxed < 1.0 && $maxed > $base, "$maxed vs $base");
$oneGood = $fitWith([$rated('g1', $hcTags, 5, 'mbid-hc')], $releases['hc']);
$fiveGood = $fitWith(array_map(static fn ($i) => $rated("g$i", $hcTags, 5, 'mbid-hc'), range(1, 5)), $releases['hc']);
check('more ratings of the artist weigh more (smoothly)', $fiveGood > $oneGood && $oneGood > $base, "$base / $oneGood / $fiveGood");
$fiveBad = $fitWith(array_map(static fn ($i) => $rated("b$i", $hcTags, 1, 'mbid-hc'), range(1, 5)), $releases['hc']);
check('consistent bad ratings outweigh a good genre match', $fiveBad < $base / 2, "$fiveBad vs $base");
$otherArtist = $fitWith([$rated('o1', $hcTags, 5, 'mbid-other')], $releases['hc']);
$sameArtistGenre = (new RecommendationEngine($taste, $config, ['ratings' => [$rated('s1', $hcTags, 5, 'mbid-hc')]]))->assess($releases['hc'])['score_parts']['genre'];
check('a rating of the same artist counts once (personal part), not also in its genres',
    abs($sameArtistGenre / (1 - 1 / 4) - $as['hc']['score_parts']['genre']) < 0.001 && $otherArtist > $base, "$sameArtistGenre");
check('a rating does not score the rated release itself', $fitWith([$rated('hc', $hcTags, 1, 'mbid-hc')], $releases['hc']) === $base);

echo "Ratings: nuance, not genre points\n";
$sameArtist = $fitWith([$rated('old', ['pop'], 1, 'mbid-hc')], $releases['hc']);
check('a rating of the same artist counts (even with other tags)', $sameArtist < $base, "$sameArtist vs $base");
check('same artist counts more than one similar release by someone else', $base - $sameArtist > $base - $byStars[0], ($base - $sameArtist) . ' vs ' . ($base - $byStars[0]));
$unrelated = $fitWith([$rated('pop1', ['pop', 'indie pop', 'synthpop'], 1)], $releases['hc']);
check('a 1-star pop rating does not touch a hardcore release', $unrelated === $base, "$unrelated vs $base");
$beat = $release('beat', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: beatdown hardcore, metallic hardcore', 'musicbrainz:artist']]);
$punk = $release('punk', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore punk, punk rock', 'musicbrainz:artist']]);
$mixed = [$rated('a', ['beatdown hardcore', 'metallic hardcore'], 5), $rated('b', ['hardcore punk', 'punk rock'], 1)];
$plain = new RecommendationEngine($taste, $config);
check('contradicting ratings in one genre coexist: the 5-star side goes up', $fitWith($mixed, $beat) > $plain->assess($beat)['fit']);
check('...and the 1-star side goes down', $fitWith($mixed, $punk) < $plain->assess($punk)['fit']);
// Releases that clearly fit (0.05 above the threshold, more than one rating's nudge)
// stay recommended; one that barely passes may drop, as it fits only weakly.
$clear = ['recommended' => ['min_fit' => $plain->assess($many['h0'])['fit'] - 0.05] + $config['recommended']] + $config;
$oneBad = (new RecommendationEngine($taste, $clear, ['ratings' => [$rated('bad', $hcTags, 1)]]))->run($many);
check('one 1-star hardcore rating keeps all 20 clearly fitting hardcore releases recommended', count(array_filter(array_column($oneBad['recommended'], 'id'), static fn ($id) => $id[0] === 'h')) === 20);
$why = (new RecommendationEngine($taste, $config, ['ratings' => [$rated('x', $hcTags, 5)]]))->run($releases);
$whyText = implode(' | ', array_column(array_column($why['recommended'], null, 'id')['hc']['reasons'] ?? [], 'text'));
check('the reason says your ratings lean toward the genre', str_contains($whyText, 'Your ratings favour'), $whyText);
echo "Ratings: related sub-genres you have not rated\n";
// Youth crew is not in the profile and never rated; its releases are tagged
// hardcore punk too. Drill releases never carry alternative hip hop.
$scene = [];
foreach (range(1, 4) as $i) {
    $scene["yc$i"] = $release("yc$i", [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore punk, youth crew', 'musicbrainz:artist']]);
    $scene["dr$i"] = $release("dr$i", [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: drill, trap', 'musicbrainz:artist']]);
}
$sceneWith = static fn (array $ratings) => (new RecommendationEngine($taste, $config, ['ratings' => $ratings]))->run($scene)['assessments'];
$hcp = static fn (int $n, int $stars, array $tags = ['hardcore punk']) => array_map(static fn ($i) => $rated("hp$stars-$i", $tags, $stars), range(1, $n));
$plainScene = $sceneWith([]);
$yc = static fn (array $as) => $as['yc1']['fit'];
$one = $sceneWith($hcp(1, 4));
$three = $sceneWith($hcp(3, 4));
$ycDirect = $sceneWith([$rated('ycr', ['youth crew'], 5)]);
$lean = static fn (array $as) => $as['yc1']['related_rating_lean']['youth crew'] ?? 0.0;
check('good hardcore punk ratings raise youth crew', $yc($one) > $yc($plainScene) && $lean($one) > 0, $yc($plainScene) . ' -> ' . $yc($one));
check('...one rating only a little (half of 1/11 at most), consistent ratings more', $lean($one) <= 0.5 / 11 && $lean($three) > $lean($one) && $yc($three) > $yc($one),
    $lean($one) . ' / ' . $lean($three));
check('...the reason names the related sub-genre', str_contains(implode(' | ', array_column($sceneWith($hcp(5, 5))['yc1']['reasons'], 'text')), 'Your ratings of related genres favour youth crew'));
check('poor hardcore punk ratings lower youth crew', $yc($sceneWith($hcp(3, 1))) < $yc($plainScene));
check('a broad tag alone is no evidence (ratings of plain "hardcore")', $yc($sceneWith($hcp(5, 5, ['hardcore']))) === $yc($plainScene));
check('a rating of an unrelated sub-genre of the same family moves nothing', $sceneWith([$rated('ahh', ['alternative hip hop'], 5)])['dr1']['fit'] === $plainScene['dr1']['fit']);
check('a sub-genre you rated yourself uses only its own ratings (never both)', isset($ycDirect['yc1']['rating_lean']['youth crew']) && !isset($ycDirect['yc1']['related_rating_lean']['youth crew']));
check('no ratings: nothing changes', $sceneWith([])['yc1']['related_rating_lean'] === []);

check('ratings can be disabled in config',(new RecommendationEngine($taste, ['ratings' => ['enabled' => false]] + $config, ['ratings' => [$rated('x', $hcTags, 1)]]))->assess($releases['hc'])['fit'] === $base);

echo "Release dates\n";
$yearOnly = $release('y', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore, metallic hardcore, beatdown hardcore', 'musicbrainz:artist']],
    ['first_release_date' => '2026', 'watch_level' => 'following']);
$probable = $release('p', [['Album', 'musicbrainz:release-group'], ['Artist genres/tags: hardcore, metallic hardcore, beatdown hardcore', 'musicbrainz:artist']],
    ['first_release_date' => '2026-10', 'date_check' => ['status' => 'probable', 'date' => '2026-10-16', 'note' => 'x', 'release_dates' => ['2026-10', '2026-10-16']]]);
$rd = (new RecommendationEngine($taste, $config))->run(['y' => $yearOnly, 'p' => $probable, 'hc' => $releases['hc']]);
$ids = [...array_column($rd['recommended'], 'id'), ...array_column($rd['wildcards'], 'id')];
check('year-only date is never presented as new - not even for a followed artist', !in_array('y', $ids, true) && $rd['assessments']['y']['decision'] === 'needs_date_check');
check('"probable" date still needs a check', !in_array('p', $ids, true) && $rd['assessments']['p']['decision'] === 'needs_date_check');
check('it is still assessed musically (so you can see it is relevant)', $rd['assessments']['y']['fit'] > 0.3);
check('confirmed day is recommended normally', in_array('hc', $ids, true));

echo "MusicBrainz 500-hit limit: name-range partitions\n";
check('whole range splits in two', CandidateFinder::splitRange(['*', '*']) === [['*', 'm'], ['m', '*']]);
check('ranges cover everything (open ends kept)', CandidateFinder::splitRange(['m', '*']) === [['m', 't'], ['t', '*']]);
check('adjacent letters cannot be split further', CandidateFinder::splitRange(['l', 'm']) === []);

echo "Store: nothing processed twice, changes detected\n";
$store = new MusicRadar\Discovery\DiscoveryStore(new PDO('sqlite::memory:'));
$rel = ['id' => '00000000-0000-0000-0000-000000000001', 'title' => 'T', 'type' => 'Album', 'first_release_date' => '2026-10-10',
    'artist' => ['name' => 'A', 'mbid' => '00000000-0000-0000-0000-0000000000aa', 'primary_name' => 'A', 'credited_artists' => 1], 'release_tags' => ['hardcore']];
check('first save is "new"', $store->saveRelease($rel, 'pool', null, '2026-10-25T10:00:00+00:00') === 'new');
check('same data again is "unchanged"', $store->saveRelease($rel, 'pool', null, '2026-10-26T10:00:00+00:00') === 'unchanged');
$store->setLastfmAlbum($rel['id'], ['name' => 'T', 'description' => '', 'tags' => []], 'found', '2026-10-26T10:00:00+00:00');
check('Last.fm data kept while the release is unchanged', $store->saveRelease($rel, 'pool', null, '2026-10-27T10:00:00+00:00') === 'unchanged' && $store->release($rel['id'])['lastfm_album_status'] === 'found');
check('changed tags are detected', $store->saveRelease(['release_tags' => ['hardcore', 'metalcore']] + $rel, 'pool', null, '2026-10-28T10:00:00+00:00') === 'changed');
check('...but Last.fm album data is kept (it was looked up by artist + title, not tags)', $store->release($rel['id'])['lastfm_album_status'] === 'found');
check('a changed title gets fresh Last.fm data', $store->saveRelease(['title' => 'T2'] + $rel, 'pool', null, '2026-10-28T10:30:00+00:00') === 'changed'
    && $store->release($rel['id'])['lastfm_album_status'] === null);
$store->saveRelease(['release_tags' => ['hardcore', 'metalcore']] + $rel, 'pool', null, '2026-10-28T10:40:00+00:00');
check('first seen is kept', $store->release($rel['id'])['first_seen_at'] === '2026-10-25T10:00:00+00:00');
$store->saveRelease(['release_tags' => ['hardcore', 'metalcore']] + $rel, 'known', 'following', '2026-10-28T11:00:00+00:00');
check('seen in pool and as known artist = one row, origin "both"', $store->release($rel['id'])['origin'] === 'both' && $store->release($rel['id'])['watch_level'] === 'following');
check('artists needing data are listed once', $store->artistsNeedingMusicBrainz('2026-10-01', '2026-10-31', '2026-10-01T00:00:00+00:00') === ['00000000-0000-0000-0000-0000000000aa']);
$store->saveArtistMusicBrainz('00000000-0000-0000-0000-0000000000aa', ['name' => 'A', 'tags' => []], '2026-10-28T12:00:00+00:00');
check('fresh artist data is not fetched again', $store->artistsNeedingMusicBrainz('2026-10-01', '2026-10-31', '2026-10-01T00:00:00+00:00') === []);
$store->fail('lastfm', 'x', 'boom', '2026-10-28T12:00:00+00:00');
$store->fail('lastfm', 'x', 'boom again', '2026-10-29T12:00:00+00:00');
check('failures are counted for retry', $store->failures()[0]['attempts'] === 2);
$store->fail('lastfm', 'x', 'third', '2026-10-29T13:00:00+00:00');
check('after max_attempts the item is paused', isset($store->pausedFailures('lastfm', 3, '2026-10-29T00:00:00+00:00')['x']));
check('...and retried once the pause is over', !isset($store->pausedFailures('lastfm', 3, '2026-10-30T14:00:00+00:00')['x']));
$old = ['id' => '00000000-0000-0000-0000-000000000002', 'first_release_date' => '2026-09-25'] + $rel;
$year = ['id' => '00000000-0000-0000-0000-000000000003', 'first_release_date' => '2026'] + $rel;
$store->saveRelease($old, 'pool', null, '2026-10-25T10:00:00+00:00');
$store->saveRelease($year, 'known', 'following', '2026-10-25T10:00:00+00:00');
foreach ([['lastfm', $old['id']], ['credits', $old['id']], ['lastfm', $rel['id']], ['lastfm', $year['id']], ['collect', '2026-09-30/album'], ['collect', '2026-10-01/ep'], ['known', 'artist-mbid']] as [$stage, $item]) {
    $store->fail($stage, $item, 'boom', '2026-10-30T12:00:00+00:00');
}
$forgotten = $store->forgetFailuresBefore('2026-10-01');
$left = array_map(static fn ($f) => $f['stage'] . ':' . $f['item'], $store->failures());
check('failures of items that left the window are forgotten (never retried)', $forgotten === 3
    && !array_intersect($left, ["lastfm:{$old['id']}", "credits:{$old['id']}", 'collect:2026-09-30/album']), json_encode($left));
check('...those still in the window, without a full date, or per artist are kept',
    !array_diff(["lastfm:{$rel['id']}", "lastfm:{$year['id']}", 'collect:2026-10-01/ep', 'known:artist-mbid', 'lastfm:x'], $left), json_encode($left));

echo "Facts from fetched data\n";
$base = ['id' => 'r', 'title' => 'Album X', 'type' => 'Album', 'first_release_date' => '2026-10-10',
    'artist' => ['name' => 'Band', 'mbid' => 'mbid-band', 'primary_name' => 'Band', 'credited_artists' => 1], 'release_tags' => []];
$lfSame = ['name' => 'Band', 'mbid' => 'mbid-band', 'bio' => 'A band.', 'tags' => ['hardcore', 'seen live', 'danish']];
$lfOther = ['name' => 'Band', 'mbid' => 'mbid-someone-else', 'bio' => 'Other band.', 'tags' => ['jazz']];
$f1 = MusicRadar\Discovery\FactBuilder::build($base, null, $lfSame, null);
check('Last.fm used when its MBID matches', $f1['lastfm_used'] && in_array('hardcore', $f1['genre_tags'], true));
check('noise tags ("seen live") are not facts', !str_contains(json_encode($f1['facts']), 'seen live'));
$f2 = MusicRadar\Discovery\FactBuilder::build($base, null, $lfOther, null);
check('Last.fm skipped when it points to another artist', !$f2['lastfm_used'] && $f2['metadata_quality'] === 'insufficient');
$f3 = MusicRadar\Discovery\FactBuilder::build($base, null, ['mbid' => ''] + $lfSame, ['name' => 'Album X', 'description' => '', 'tags' => ['metalcore']]);
check('without MBID, Last.fm is used only if it knows this album', $f3['lastfm_used'] && in_array('metalcore', $f3['genre_tags'], true));
$f4 = MusicRadar\Discovery\FactBuilder::build($base, null, ['mbid' => ''] + $lfSame, null);
check('without MBID and without the album: skipped', !$f4['lastfm_used']);

echo "Pool records\n";
$rg = ['id' => 'x', 'title' => 'Comp', 'primary-type' => 'Album', 'first-release-date' => '2026-10-10',
    'artist-credit' => [['name' => 'Various Artists', 'artist' => ['id' => CandidateFinder::VARIOUS_ARTISTS, 'name' => 'Various Artists']]]];
check('"Various Artists" is never a candidate', CandidateFinder::toRelease($rg) === null);
check('reissue titles are recognised', CandidateFinder::looksLikeReissue('Gold (A Decade of Soul)') && !CandidateFinder::looksLikeReissue('Riot Youth'));

echo "Candidate rules\n";
$q = CandidateFinder::baseQuery('2026-09-25', '2026-10-25');
check('only albums and EPs', str_contains($q, 'primarytype:album OR primarytype:ep'));
check('first release date window (no reissues)', str_contains($q, 'firstreleasedate:[2026-09-25 TO 2026-10-25]'));
check('no live/compilation/remix (secondary types)', str_contains($q, '-(secondarytype:* AND -secondarytype:"mixtape/street")'));
$mixtape = ['id' => 'm', 'title' => 'Tape', 'primary-type' => 'Album', 'first-release-date' => '2026-10-10',
    'artist-credit' => [['name' => 'A', 'artist' => ['id' => 'a1', 'name' => 'A']]]];
check('a mixtape is a candidate', CandidateFinder::toRelease($mixtape + ['secondary-types' => ['Mixtape/Street']]) !== null);
check('a DJ-mix mixtape is not', CandidateFinder::toRelease($mixtape + ['secondary-types' => ['DJ-mix', 'Mixtape/Street']]) === null);
check('a live album is not', CandidateFinder::toRelease($mixtape + ['secondary-types' => ['Live']]) === null);

echo "Expanded editions
";
// Three older albums get a new release on 2026-11-13: a deluxe with 5 new songs,
// a remaster (same 12 tracks) and an edition titled like the album.
$rel = static fn (string $rg, string $rgTitle, string $title, string $date, int $tracks) => ['title' => $title, 'date' => $date, 'track-count' => $tracks,
    'artist-credit' => [['name' => 'Singer', 'artist' => ['id' => 'a1', 'name' => 'Singer']]],
    'release-group' => ['id' => $rg, 'title' => $rgTitle, 'primary-type' => 'Album']];
$releases = [
    $rel('rg-deluxe', 'Cruel World', 'Cruel World…So It Ends', '2026-11-13', 17),
    $rel('rg-deluxe', 'Cruel World', 'Cruel World', '2026-01-23', 12),
    $rel('rg-remaster', 'Old Songs', 'Old Songs (2026 Remaster)', '2026-11-13', 12),
    $rel('rg-remaster', 'Old Songs', 'Old Songs', '1999-05-01', 12),
    $rel('rg-same', 'Same Name', 'Same Name', '2026-11-13', 20),
    $rel('rg-same', 'Same Name', 'Same Name', '2020-01-01', 10),
    $rel('rg-new', 'Brand New', 'Brand New (Bonus)', '2026-11-13', 14),
];
$requests = 0;
$mb = new MusicBrainzClient(null, 0, 15, 1, static function (string $url) use ($releases, &$requests): array {
    $requests++;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $hits = str_contains($q['query'], 'rgid:(')
        ? array_filter($releases, static fn ($r) => $r['date'] < '2026-11-13' && str_contains($q['query'], $r['release-group']['id']))
        : array_filter($releases, static fn ($r) => $r['date'] === '2026-11-13');
    return [200, json_encode(['count' => count($hits), 'releases' => array_values($hits)]), ''];
});
$errors = [];
$editions = (new CandidateFinder($mb))->readEditions('2026-11-13', 'album', ['rg-first-today' => true], null, $errors);
$byId = array_column($editions ?? [], null, 'id');
check('a deluxe with more tracks than every earlier release counts as new music', isset($byId['rg-deluxe']) && $errors === []);
check('... on its own date and title', ($byId['rg-deluxe']['first-release-date'] ?? '') === '2026-11-13' && ($byId['rg-deluxe']['title'] ?? '') === 'Cruel World…So It Ends'
    && CandidateFinder::toRelease($byId['rg-deluxe']) !== null);
check('a remaster is not', !isset($byId['rg-remaster']));
check('an edition titled like its album is not looked at', !isset($byId['rg-same']));
check('an album with no earlier release is not an edition', !isset($byId['rg-new']));
check('two requests: the day releases and one check', $requests === 2, "$requests");
check('search tags include neighbouring genres', in_array('noise rock', ProfileSummary::searchTags($profile), true));

echo "No paid services\n";
$src = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src')) as $f) {
    if ($f->isFile() && str_ends_with((string) $f, '.php')) {
        $src .= file_get_contents((string) $f);
    }
}
check('no AI/LLM API calls in the code', !preg_match('/anthropic|openai|api\.mistral|generativelanguage/i', $src));
check('no Composer dependencies', !is_dir(__DIR__ . '/../vendor') && !is_file(__DIR__ . '/../composer.json'));

echo "Tag classification: genres, properties, noise, unknown\n";
$is = static fn (string $kind, array $tags) => array_filter($tags, static fn ($t) => TagClassifier::classify($t) !== $kind) === [];
check('documented genres (MusicBrainz list) stay genres', $is('genre', ['hardcore punk', 'beatdown hardcore', 'metalcore', 'melodic hardcore', 'hard techno',
    'drum and bass', 'indie pop', 'alternative hip hop', 'crossover thrash', 'dance-punk']));
check('small subgenres not on the list are still recognised by their genre word', $is('genre', ['metallic hardcore', 'gothic hardcore', 'synthpunk',
    'electro techno', 'danish hip hop']));
check('musical properties are kept apart from genres', $is('property', ['aggressive', 'chaotic', 'dissonant', 'harsh vocals', 'shrieking', 'raw', 'atmospheric', 'melodic'])
    && TagClassifier::property('harsh vocals') === 'vocals' && TagClassifier::property('raw') === 'production');
check('"melodic" is a property, "melodic hardcore" a genre', TagClassifier::classify('melodic') === 'property' && TagClassifier::classify('melodic hardcore') === 'genre');
check('noise from real data is noise', $is('noise', ['animal on cover', 'myspace', '2026 releases', 'offizielle charts', '5+ wochen', '1–4 wochen',
    'plattentests.de', 'ph_2_stars', 'i have this album', 'seen live']));
check('record labels and nationalities are meta', $is('meta', ['pure noise records', 'epitaph records', 'danish', '90s', '2024']));
check('anything unrecognised is unknown - never a genre by default', $is('unknown', ['te amo lucas', 'laptop', 'grief', 'antireligious']));
check('spelling variants merge (MusicBrainz spelling preferred)', TagClassifier::canonical('neo-soul') === 'neo soul' && TagClassifier::canonical('Psy-Trance') === 'psytrance'
    && TagClassifier::canonical('alternative rnb') === 'alternative r&b' && TagClassifier::canonical('synthpunk') === TagClassifier::canonical('synth punk')
    && TagClassifier::canonical('Hip-Hop') === 'hip hop');
check('happy hardcore is electronic (rave), also in longer tags', TagClassifier::family('happy hardcore') === 'Electronic'
    && TagClassifier::family('German Happy Hardcore') === 'Electronic' && TagClassifier::family('happy-hardcore') === 'Electronic');
$hardcoreFamily = ['hardcore', 'hardcore punk', 'beatdown hardcore', 'melodic hardcore', 'metallic hardcore', 'straight edge hardcore', 'youth crew', 'new york hardcore', 'crossover'];
check('...while hardcore and hardcore punk stay Hardcore', array_filter($hardcoreFamily, static fn ($t) => TagClassifier::family($t) !== 'Hardcore') === []);
check('...and the other "hardcore" genres keep their family', TagClassifier::family('hardcore techno') === 'Electronic' && TagClassifier::family('hardcore hip hop') === 'Hip hop'
    && TagClassifier::family('post-hardcore') === 'Post-hardcore & emo' && TagClassifier::family('happy trance') === 'Electronic');
check('...and it is still a genre', TagClassifier::classify('happy hardcore') === 'genre');
check('rave / hardcore techno genres are electronic (MusicBrainz relations)', TagClassifier::family('freeform hardcore') === 'Electronic'
    && TagClassifier::family('uptempo hardcore') === 'Electronic' && TagClassifier::family('breakbeat hardcore') === 'Electronic'
    && TagClassifier::family('deathchant hardcore') === 'Electronic' && TagClassifier::family('industrial hardcore') === 'Electronic'
    && TagClassifier::family('hardcore breaks') === 'Electronic');
check('ambiguous ones are not guessed: uk hardcore (rave or UK punk), digital hardcore (fusion)', TagClassifier::family('uk hardcore') === 'Hardcore'
    && TagClassifier::family('digital hardcore') === 'Hardcore');
check('metalcore stays Metalcore', TagClassifier::family('metalcore') === 'Metalcore' && TagClassifier::family('deathcore') === 'Metalcore');
check('a family is only given to genres - "emotional" is a property, not emo',TagClassifier::classify('emotional') === 'property' && TagClassifier::familiesOf(['emotional']) === []);

$noisy = $release('noisy', [
    ['Album "N" by Y, first released 2026-10-10', 'musicbrainz:release-group'],
    ['Release tags: beatdown hardcore, metallic hardcore, animal on cover, offizielle charts, te amo lucas, aggressive, harsh vocals', 'musicbrainz:release-group'],
    ['Artist genres/tags: hardcore, metalcore', 'musicbrainz:artist'],
]);
$clean = $release('clean', [
    ['Album "N" by Y, first released 2026-10-10', 'musicbrainz:release-group'],
    ['Release tags: beatdown hardcore, metallic hardcore', 'musicbrainz:release-group'],
    ['Artist genres/tags: hardcore, metalcore', 'musicbrainz:artist'],
]);
$plain = new RecommendationEngine($taste, $config);
check('noise, unknown tags and properties do not change the score', $plain->assess($noisy)['fit'] === $plain->assess($clean)['fit']
    && array_keys($plain->assess($noisy)['tags']) === array_keys($plain->assess($clean)['tags']), json_encode([$plain->assess($noisy)['tags'], $plain->assess($clean)['tags']]));

$facts = MusicRadar\Discovery\FactBuilder::build(
    ['id' => 'p', 'title' => 'P', 'type' => 'Album', 'first_release_date' => '2026-10-10', 'release_tags' => ['metalcore', 'chaotic', 'harsh vocals', 'animal on cover'],
        'artist' => ['name' => 'Band', 'mbid' => 'mbid-band', 'primary_name' => 'Band', 'credited_artists' => 1]],
    ['name' => 'Band', 'tags' => [['name' => 'hardcore', 'count' => 5], ['name' => 'aggressive', 'count' => 3]]],
    ['name' => 'Band', 'mbid' => 'mbid-band', 'bio' => '', 'tags' => ['metalcore', 'melodic']],
    null,
);
$levels = array_map(static fn ($p) => "{$p['name']}/{$p['dimension']}/{$p['level']}/{$p['source']}", $facts['properties']);
check('properties keep their source and level: album properties are not artist properties', $levels === [
    'chaotic/sound/album/musicbrainz:release-group', 'harsh vocals/vocals/album/musicbrainz:release-group',
    'aggressive/energy/artist/musicbrainz:artist', 'melodic/sound/artist/lastfm:artist'], json_encode($levels));
check('...and the original tag and source weight are kept', $facts['properties'][2]['tag'] === 'aggressive' && $facts['properties'][2]['weight'] === 3);
check('...the original source data stays in the facts (nothing lost)', str_contains(json_encode($facts['facts']), 'animal on cover'));
check('...and only genres reach the genre tags', $facts['genre_tags'] === ['metalcore', 'hardcore'], json_encode($facts['genre_tags']));

$stored = ['genres' => [['name' => 'hardcore', 'weight' => 0.2], ['name' => 'offizielle charts', 'weight' => 0.05], ['name' => 'neo-soul', 'weight' => 0.02],
    ['name' => 'neo soul', 'weight' => 0.01], ['name' => 'aggressive', 'weight' => 0.03]],
    'families' => [['name' => 'Hardcore', 'weight' => 0.2], ['name' => 'Other', 'weight' => 0.08], ['name' => 'R&B & soul', 'weight' => 0.03]],
    'attributes' => [], 'artists' => [['name' => 'A', 'lastfm' => ['listening' => 'core'], 'genres' => ['weights' => ['hardcore' => 0.7, 'myspace' => 0.3], 'families' => []]]]];
$model = TasteModel::fromProfile($stored, $config);
check('a stored profile is re-classified when loaded (no API calls): noise and properties drop out, variants merge',
    $model->genres === ['hardcore' => 0.2, 'neo soul' => 0.03] && $model->families === ['Hardcore' => 0.2, 'R&B & soul' => 0.03], json_encode([$model->genres, $model->families]));
check('...also in the artists used for "close to"', $model->artists[0]['vector'] === ['hardcore' => 0.7]);

echo "Data confidence: how much genre information, not how many tags\n";
$withTags = static fn (string $id, string $tags) => $release($id, [['Album "X" by Y, first released 2026-10-10', 'musicbrainz:release-group'],
    ["Artist genres/tags: $tags", 'musicbrainz:artist'], ["Artist tags on Last.fm (strongest first): $tags", 'lastfm:artist']]);
$info = static fn (string $tags) => $plain->assess($withTags('i', $tags));
check('broad genres give less confidence than specific ones', $info('rap, hip hop')['confidence'] < $info('beatdown hardcore, metallic hardcore')['confidence'],
    json_encode([$info('rap, hip hop')['genre_information'], $info('beatdown hardcore, metallic hardcore')['genre_information']]));
check('spelling variants do not add confidence', $info('indie-rock, indie rock')['genre_information'] == $info('indie rock')['genre_information']);
check('a tag contained in a more specific one adds nothing ("hardcore" next to "beatdown hardcore")',
    $info('hardcore, beatdown hardcore')['genre_information']['amount'] === $info('beatdown hardcore')['genre_information']['amount']);
check('independent specific genres add up to full confidence', $info('beatdown hardcore, metalcore, hardcore punk')['confidence'] === 1.0);
check('confidence never changes the music score (fit is the sum of its parts)', abs(array_sum($info('rap, hip hop')['score_parts']) - $info('rap, hip hop')['fit']) < 0.001);
$broad = $plain->run(['b' => $withTags('b', 'rap, hip hop')]);
check('only broad genres: an honest caveat - and too little evidence to be recommended',
    in_array('Limited music data: only broad genres (rap, hip hop)', array_column($broad['assessments']['b']['reasons'], 'text'), true)
    && $broad['recommended'] === [] && $broad['assessments']['b']['fit'] < $config['recommended']['min_fit'],
    json_encode([$broad['assessments']['b']['fit'], $broad['assessments']['b']['not_selected'] ?? null, array_column($broad['assessments']['b']['reasons'], 'text')]));

echo "Recommended needs solid evidence; your own low rating counts\n";
$strong = $withTags('s', 'beatdown hardcore, metallic hardcore, hardcore, metalcore');
$weak = $withTags('w', 'pop rock, singer-songwriter, folk');
$rs = $plain->run(['s' => $strong, 'w' => $weak]);
check('several specific genres you listen to: recommended', in_array('s', array_column($rs['recommended'], 'id'), true),
    json_encode([$rs['assessments']['s']['fit'], $rs['assessments']['s']['signals']]));
check('a thin, broad genre match is not', !in_array('w', array_column($rs['recommended'], 'id'), true),
    json_encode([$rs['assessments']['w']['fit'], $rs['assessments']['w']['artist_match'], $rs['assessments']['w']['not_selected'] ?? null]));
$dormant = $profile;
foreach ($dormant['artists'] as &$art) {
    $art['lastfm']['listening'] = 'dormant';
}
unset($art);
$rd2 = (new RecommendationEngine(TasteModel::fromProfile($dormant, $config), $config))->run(['s' => $strong]);
check('a dormant favourite still counts: the genre match carries it', in_array('s', array_column($rd2['recommended'], 'id'), true)
    && $rd2['assessments']['s']['signals']['tag_overlap'] < $rs['assessments']['s']['signals']['tag_overlap'],
    json_encode([$rd2['assessments']['s']['fit'], $rd2['assessments']['s']['signals']]));
$own4 = (new RecommendationEngine($taste, $config, ['ratings' => [['release_id' => 'w', 'stars' => 4, 'tags' => ['pop rock'], 'artist_mbid' => 'mbid-w']]]))->run(['w' => $weak]);
check('your own 4-5 star rating of the release is a reason in itself', in_array('w', array_column($own4['recommended'], 'id'), true) || $own4['assessments']['w']['fit'] < $config['recommended']['min_fit'],
    json_encode([$own4['assessments']['w']['fit'], $own4['assessments']['w']['not_selected'] ?? null]));
$lowRated = (new RecommendationEngine($taste, $config, ['ratings' => [['release_id' => 's', 'stars' => 2, 'tags' => ['hardcore'], 'artist_mbid' => 'mbid-s']]]))
    ->run(['s' => $strong, 'f' => ['id' => 'f', 'watch_level' => 'following'] + $strong]);
check('a release you rated 1-2 stars is not recommended to you', !in_array('s', array_column($lowRated['recommended'], 'id'), true)
    && str_contains($lowRated['assessments']['s']['not_selected'] ?? '', 'you rated this release 2'));
echo "Interested respects release-specific feedback\n";
foreach ([null, 1, 2, 3, 4, 5] as $stars) {
    foreach (['interested', 'following'] as $level) {
        // Both releases have the same artist; the second has no rating and no tags.
        $pair = [
            'f' => ['id' => 'f', 'watch_level' => $level] + $strong,
            'other' => ['id' => 'other', 'watch_level' => $level, 'facts' => []] + $strong,
        ];
        $feedback = ['ratings' => $stars === null ? [] : [$rated('f', ['hardcore'], $stars, $strong['artist']['mbid'])]];
        $result = (new RecommendationEngine($taste, $config, $feedback))->run($pair);
        $recommended = array_column($result['recommended'], 'id');
        $negative = $stars !== null && $stars <= 2;
        check("$level, rating " . ($stars ?? 'none') . ': only 1-2 stars override inclusion',
            in_array('f', $recommended, true) === !$negative && $result['wildcards'] === []);
        check('...the other release of the same artist keeps its guarantee, even without tags', in_array('other', $recommended, true));
        check('...the rated release is still assessed with its score', isset($result['assessments']['f']['fit'])
            && (!$negative || str_contains($result['assessments']['f']['not_selected'] ?? '', "you rated this release $stars")));
    }
}
$interested = ['f' => ['id' => 'f', 'watch_level' => 'interested'] + $strong];
$hidden = (new RecommendationEngine($taste, $config, ['not_interested_releases' => ['f']]))->run($interested);
check('Not interested overrides Interested for both categories', $hidden['recommended'] === [] && $hidden['wildcards'] === []);

echo "Model v2: one genre signal, evidence instead of repetition\n";
$two = $withTags('two', 'hip hop, indie pop');
$wide = $withTags('wide', 'beatdown hardcore, metallic hardcore, hardcore, metalcore');
check('two tags (one broad) stay around the threshold at most - far from a high score', $plain->assess($two)['fit'] < 0.45, (string) $plain->assess($two)['fit']);
check('several specific genres you listen to score higher than one or two', $plain->assess($wide)['fit'] > $plain->assess($two)['fit'] + 0.1);
$one = $plain->assess($withTags('one', 'beatdown hardcore'));
check('one shared tag with an artist is limited evidence (overlap at most a third)', $one['signals']['tag_overlap'] <= 1 / 3 + 1e-9, json_encode($one['signals']));
check('families and blends give no points of their own', !isset($one['score_parts']['family']) && !isset($one['score_parts']['blend']));
check('the score is the genre signal plus independent terms - nothing counted twice',
    abs($one['score_parts']['genre'] - max($one['signals']['genre_match'], $one['signals']['tag_overlap'])) < 0.001, json_encode([$one['score_parts'], $one['signals']]));
$withLink = static fn (array $r, float $s) => ['similar' => ['identity' => 'mbid', 'links' => ['Knocked Loose' => ['s' => $s, 'out' => $s, 'in' => 0.0]]]] + $r;
$ghost = $withTags('ghost', 'classical, orchestral');
check('Last.fm alone never reaches Recommended (capped)', $plain->assess($withLink($ghost, 1.0))['fit'] < $config['recommended']['min_fit'],
    (string) $plain->assess($withLink($ghost, 1.0))['fit']);
check('a Last.fm link adds to genre evidence', $plain->assess($withLink($wide, 0.6))['fit'] > $plain->assess($wide)['fit']);
$lowPlays = $profile;
$lowPlays['artists'][0]['lastfm']['play_weight'] = 0.2; // Knocked Loose, little played
$lowEngine = new RecommendationEngine(TasteModel::fromProfile($lowPlays, $config), $config);
$lfmOf = static fn (RecommendationEngine $e) => $e->assess($withLink($wide, 0.6))['signals']['lastfm'];
check('a link to an artist you play less counts less, but at least half', $lfmOf($lowEngine) < $lfmOf($plain) && $lfmOf($lowEngine) >= 0.5 * $lfmOf($plain) - 0.001,
    $lfmOf($lowEngine) . ' vs ' . $lfmOf($plain));
$likesKl = new RecommendationEngine($taste, $config, ['ratings' => [['release_id' => 'klr', 'artist_mbid' => md5('Knocked Loose'), 'artist' => 'Knocked Loose', 'title' => 'T', 'tags' => [], 'stars' => 5]]]);
check('a good rating of that artist strengthens the link (at most 1.5x)', $lfmOf($likesKl) > $lfmOf($plain) && $lfmOf($likesKl) <= 1.5 * $lfmOf($plain) + 0.001,
    $lfmOf($likesKl) . ' vs ' . $lfmOf($plain));
$dislikesKl = new RecommendationEngine($taste, $config, ['ratings' => [['release_id' => 'klr', 'artist_mbid' => md5('Knocked Loose'), 'artist' => 'Knocked Loose', 'title' => 'T', 'tags' => [], 'stars' => 1]]]);
check('...a bad rating does not weaken it (only the artist itself is rated down)', $lfmOf($dislikesKl) === $lfmOf($plain));
check('no Last.fm data is never a penalty', $plain->assess(['similar' => ['identity' => 'none', 'links' => []]] + $wide)['fit'] === $plain->assess($wide)['fit']);
$thinOne = $release('t1', [['Album', 'musicbrainz:release-group'], ['Release tags: beatdown hardcore', 'musicbrainz:release-group']], ['metadata_quality' => 'partial']);
check('a documented Last.fm link counts toward data confidence', $plain->assess($withLink($thinOne, 0.8))['confidence'] > $plain->assess($thinOne)['confidence']);
check('...the reason names the Last.fm link honestly', str_contains(json_encode($plain->assess($withLink($wide, 0.6))['reasons']), 'Last.fm Similar Artists connects it to Knocked Loose'));
check('no reason claims a sound ("close to")', !str_contains(json_encode($plain->assess($withLink($wide, 0.6))['reasons']), 'close to'));
$sub = $plain->assess($withTags('sub', 'techno, industrial techno'));
check('a sub-genre of a genre you like counts (half): "industrial techno" via "techno"', $sub['signals']['genre_match'] > 0, json_encode($sub['signals']));

echo "Model v2: how much a tag tells (learned from the releases)\n";
$vec = [];
for ($i = 0; $i < 300; $i++) {
    // 30 styles, each on 1/30 of the releases, always with its own companion tag.
    $style = ['beatdown hardcore', 'hard techno'][$i % 30] ?? 'style ' . ($i % 30);
    $mate = ['metallic hardcore', 'industrial techno'][$i % 30] ?? 'mate ' . ($i % 30);
    $vec[] = [$style => 1.0, $mate => 0.8, 'rock' => 0.5, 'pop rock' => $i % 2 ? 0.4 : 0.0] + ($i === 7 ? ['vague thing' => 0.3] : []);
}
$vec = array_map(static fn ($v) => array_filter($v), $vec);
$ti = TagInformation::fromVectors($vec, $config);
check('a broad tag on everything tells little', $ti['info']['rock'] < 0.4, json_encode($ti['info']['rock']));
check('a broad compound ("pop rock") is capped at half', $ti['info']['pop rock'] <= $config['tag_information']['broad_compound_cap']);
check('a specific style whose releases resemble each other tells a lot', $ti['info']['beatdown hardcore'] > 0.7 && $ti['info']['hard techno'] > 0.7, json_encode([$ti['info']['beatdown hardcore'], $ti['info']['hard techno']]));
check('a rare tag is not automatically strong evidence', $ti['info']['vague thing'] < 1.0, (string) $ti['info']['vague thing']);
check('"full information" follows the scale (two typical specific tags)', $ti['full'] > 1.0 && $ti['full'] <= 2.0, (string) $ti['full']);
$smallRun = (new RecommendationEngine(TasteModel::fromProfile($profile, $config), $config))->run(['s' => $strong]);
check('learned only from enough releases (few: the short broad-tag list is used)', $smallRun['assessments']['s']['genre_information']['amount'] === $plain->assess($strong)['genre_information']['amount']);

echo "Model v2: Last.fm Similar Artists links\n";
$row = static fn (string $name, ?string $mbid, string $identity, array $links, ?string $page = null) => ['artist_key' => SimilarArtists::key($mbid, $name),
    'name' => $name, 'mbid' => $mbid, 'lastfm_page' => $page ?? $name, 'identity' => $identity, 'role' => 'release', 'links' => $links, 'list_size' => count($links), 'fetched_at' => 'x'];
$rows = [];
foreach ([
    $row('Band A', 'a-mbid', 'mbid', [['Knocked Loose', md5('Knocked Loose'), 0.8]]),
    $row('Band B', null, 'name', [['Knocked Loose', '', 0.8]]),
    $row('Band C', null, 'corrected', [['Knocked Loose', '', 0.8]], 'Band See'),
    $row('Band D', 'd-mbid', 'confirmed', [], 'Band D'),
    $row('Knocked Loose', md5('Knocked Loose'), 'mbid', [['Band D', '', 0.5], ['Band E', 'e-mbid', 0.6], ['Band F', '', 0.4]]),
] as $x) {
    $rows[$x['artist_key']] = $x;
}
$sa = new SimilarArtists($rows);
$pa = [['name' => 'Knocked Loose', 'mbid' => md5('Knocked Loose')], ['name' => 'Initiate', 'mbid' => md5('Initiate')]];
$l = static fn (string $name, ?string $mbid = null) => $sa->links(['name' => $name, 'mbid' => $mbid], $pa);
check('found by MusicBrainz id: the link counts fully', ($l('Band A', 'a-mbid')['links']['Knocked Loose']['s'] ?? 0) === 0.8);
check('found by name only: half', ($l('Band B')['links']['Knocked Loose']['s'] ?? 0) === 0.4);
check('autocorrected to another name: not used', $l('Band C')['links'] === [] && $l('Band C')['identity'] === 'corrected');
check('the other direction counts too (the release artist in your artist\'s list)', ($l('Band E', 'e-mbid')['links']['Knocked Loose']['in'] ?? 0) === 0.6);
check('...confirmed via MusicBrainz\' Last.fm link: the page name identifies it fully', ($l('Band D', 'd-mbid')['links']['Knocked Loose']['in'] ?? 0) === 0.5);
check('...by name only it counts half', ($l('Band F')['links']['Knocked Loose']['in'] ?? 0) === 0.2);
check('no data: no link (and no penalty)', $l('Unknown Band')['links'] === [] && $l('Unknown Band')['identity'] === 'none');
// A name match linked to several DIFFERENT artists of your profile is more plausible - not proven.
$rows2 = $rows;
foreach ([
    $row('Band G', null, 'name', [['Knocked Loose', md5('Knocked Loose'), 0.8], ['Initiate', md5('Initiate'), 0.8]]),
    $row('Band H', null, 'name', [['Hub Star', md5('Hub Star'), 0.8], ['Initiate', md5('Initiate'), 0.8]]),
] as $x) {
    $rows2[$x['artist_key']] = $x;
}
for ($i = 0; $i < 30; $i++) { // a profile artist that appears in nearly every list
    $x = $row("Filler $i", "f-$i", 'mbid', [['Hub Star', md5('Hub Star'), 0.5]]);
    $rows2[$x['artist_key']] = $x;
}
$pa2 = [...$pa, ['name' => 'Hub Star', 'mbid' => md5('Hub Star')]];
$sa2 = new SimilarArtists($rows2);
$g = $sa2->links(['name' => 'Band G', 'mbid' => null], $pa2);
check('a name match linked to two different profile artists gains certainty', $g['certainty'] > 0.5 && ($g['links']['Knocked Loose']['out'] ?? 0) > 0.4, json_encode($g));
check('...but never the certainty of a documented MusicBrainz match', $g['certainty'] <= 0.75 && $g['identity'] === 'name');
check('...and a single link does not support itself', $sa2->links(['name' => 'Band B', 'mbid' => null], $pa2)['certainty'] === 0.5);
$h = $sa2->links(['name' => 'Band H', 'mbid' => null], $pa2);
check('a link to an artist found in many lists (a hub) supports identity less', ($h['links']['Initiate']['out'] ?? 0) < ($g['links']['Initiate']['out'] ?? 0),
    json_encode([$h['links']['Initiate'] ?? null, $g['links']['Initiate'] ?? null]));
check('a documented MusicBrainz match stays the most certain', $sa2->links(['name' => 'Band A', 'mbid' => 'a-mbid'], $pa2)['certainty'] === 1.0);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
