<?php

// Manual decisions about artists, keyed by the exact Last.fm artist name.
// These always win over automatic matching and the follow rules.
// Not secret (no keys here) and not reachable over HTTP (api/data is denied).
//
//   'mbid'  => '<MusicBrainz ID>'  confirm this MusicBrainz artist
//   'mbid'  => false               there is no MusicBrainz entry - stop asking
//   'level' => 'following'         you follow it: new albums/EPs always included
//   'level' => 'interested'        keep watching it, releases are evaluated
//   'level' => 'ignored'           don't watch this artist
//   'note'  => '...'               why, for future you
//
// An artist not listed here is 'interested' if it is (or was) among your
// Last.fm top artists - see FollowPolicy.

return [
    // --- MusicBrainz matches confirmed by you (2026-10-25) ---------------
    'SYL' => [
        'mbid' => '72481187-d9fe-4091-928d-0599af00e457',
        'note' => 'Copenhagen punk band - NOT Strapping Young Lad (which has "SYL" as an alias)',
    ],
    'Speed' => [
        'mbid' => '4ccddb42-9be6-46d8-822c-d9974962abec',
        'note' => 'The Australian hardcore band, not the Japanese or Korean groups',
    ],
    'Boundaries' => [
        'mbid' => 'bc32ade1-1352-438f-9cf9-38cc6aa31945',
        'note' => 'Metalcore band from Connecticut (Last.fm pointed to a Danish post-punk band)',
    ],
    'Siamese' => [
        'mbid' => '4d4760b1-f4de-4792-b63d-895e3f8163ad',
        'note' => 'Danish metal/alternative band',
    ],
    'Cabal' => [
        'mbid' => '5ec98a44-ce37-4264-abfc-0496e389538d',
        'note' => 'Metalcore/deathcore from Copenhagen',
    ],
    'Poppy' => [
        'mbid' => '4ad8190b-d2fd-46f8-a3d9-c0f55c034976',
        'note' => 'US post-genre artist ("That Poppy") - mixes pop and metal on purpose',
    ],
    'Loke Deph' => [
        'mbid' => 'c93f039e-2690-4b3b-8630-b9bbf7466860',
        'note' => 'Danish rapper',
    ],
    'Helena Gao' => [
        'mbid' => '88ba8757-4999-42c8-a437-73b6aaae74da',
        'note' => 'Only MusicBrainz entry with this name',
    ],
    'Eyes' => [
        'mbid' => 'bf5bf232-32c7-4ee6-ba96-da62a1d70419',
        'note' => 'Danish hardcore band from Copenhagen (bandcamp: eyeshxc). Verified: its albums EYES, '
            . 'Underperformer, Congratulations and SPINNER match your scrobbles. Last.fm pointed to a Swedish '
            . 'hard rock band. MusicBrainz also has a duplicate entry without releases: '
            . 'f9cb67ff-c9b9-4c85-873c-c1979b207aaa',
    ],
];
