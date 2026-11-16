<?php

// Settings for the rule-based recommendation engine (MusicRadar\Recommend).
// Tune here - no code changes needed. All component scores are 0..1.
// Free to run: uses only our own music profile + MusicBrainz/Last.fm data.
//
// The music score (0..1) combines independent evidence, each with a ceiling:
//   music = 1 - (1 - genre) x (1 - lastfm_cap x Last.fm links)
// genre is ONE signal (each tag once; tag overlap with one of your artists is
// only an alternative route to it). Thresholds and the other terms are on the
// same scale: the earlier settings x 1/0.76 (the earlier genre parts summed to
// at most 0.76, the music score to 1.0) - their relative effect is unchanged.

return [
    // Terms added to the music score.
    'weights' => [
        'danish' => 0.0,         // Danish artist (scaled by your Danish share) - switched off: no bonus for nationality
        'known_artist' => 0.0,   // artist you follow / are interested in - switched off: those releases are always included
                                 // and their genres are already in your profile, so a bonus on top rewarded them twice
        'outside' => 0.461,      // PENALTY: mostly genres unrelated to your taste
    ],

    'model' => [
        // Last.fm Similar Artists can give at most this share of the music
        // score: alone it never reaches Recommended; with genre evidence it adds.
        'lastfm_cap' => 0.40,
        // A link to one of your artists counts by how much you play it: from
        // lastfm_play_floor (little played) to 1 (most played; play_weight from
        // the profile), and up to 1 + lastfm_rating_boost when you rated the
        // artist well (its shrunk rating lean, as RatingSignal's artist part).
        'lastfm_play_floor' => 0.5,
        'lastfm_rating_boost' => 0.5,
        // Documented Last.fm links count toward data confidence (this much at most).
        'lastfm_confidence' => 0.50,
        // A sub-genre that is not in your profile, of a family you like, counts
        // this much (each tag once - a family gives no points of its own).
        'family_fallback' => 0.50,
        // A tag counts as specific from this information value (TagInformation).
        'specific_min' => 0.50,
    ],

    // How much a genre tag tells, learned from the releases (TagInformation).
    'tag_information' => [
        // Learned only from enough releases; below this the short broad-tag list is used.
        'min_releases' => 200,
        'frequency_ref' => 100,      // a tag on 1/100 of the releases or fewer: no frequency discount
        'cohesion_prior' => 5,       // rare tags are shrunk toward average cohesion
        'ref_percentile' => 0.9,     // cohesion at this percentile = full
        'ref_min_releases' => 10,    // ...among tags on at least this many releases
        'broad_compound_cap' => 0.5, // "pop rock", "indie pop": broad words only
    ],

    // A genre counts as fully "liked" from this weight in the genre profile.
    // Your taste has several centres (hardcore, pop, hip hop, techno ...); a
    // genre doesn't need to be your biggest to count.
    'liked_genre_ref' => 0.015,

    // Titles that suggest compilations/reissues/live records are skipped.
    'exclude_title_pattern' => '/\b(remaster(ed)?|reissue|re-issue|deluxe|anniversary|live at|live in|remix(es)?|greatest hits|best of|a decade of|anthology|retrospective|collection|essentials|the very best)\b/i',

    // Listening layers: how much an artist in each layer counts as a "neighbour".
    // Dormant favourites still count - quiet lately is not disliked.
    'layer_weights' => ['core' => 1.0, 'current' => 0.9, 'recent' => 0.7, 'dormant' => 0.5],

    // Broad tags say little about a sound; with learned tag information they
    // cap it at generic_weight (and tags made of these words at broad_compound_cap).
    'generic_tags' => ['rock', 'pop', 'electronic', 'alternative', 'indie', 'metal', 'hip hop', 'rap', 'punk',
        'dance', 'experimental', 'instrumental', 'singer-songwriter', 'folk', 'jazz', 'soul', 'world'],
    'generic_weight' => 0.35,

    // Weight of each data source when building a release's tag profile.
    'source_weights' => [
        'musicbrainz:release-group' => 1.2, // tags on the release itself
        'musicbrainz:artist' => 1.0,
        'lastfm:artist' => 1.0,             // ordered strongest first; decays per position
        'lastfm:album' => 1.1,
    ],

    // Families close enough to count as a bridge rather than "outside".
    'related_families' => [
        'Hardcore' => ['Punk', 'Post-hardcore & emo', 'Metalcore', 'Metal'],
        'Punk' => ['Hardcore', 'Post-hardcore & emo', 'Rock & indie'],
        'Post-hardcore & emo' => ['Hardcore', 'Punk', 'Metalcore', 'Rock & indie'],
        'Metalcore' => ['Hardcore', 'Metal', 'Post-hardcore & emo'],
        'Metal' => ['Metalcore', 'Hardcore', 'Rock & indie'],
        'Rock & indie' => ['Punk', 'Metal', 'Pop', 'Folk & singer-songwriter', 'Post-hardcore & emo'],
        'Pop' => ['Electronic', 'R&B & soul', 'Rock & indie', 'Folk & singer-songwriter', 'Hip hop'],
        'Electronic' => ['Pop', 'Hip hop'],
        'Hip hop' => ['R&B & soul', 'Electronic', 'Pop'],
        'R&B & soul' => ['Hip hop', 'Pop'],
        'Folk & singer-songwriter' => ['Rock & indie', 'Pop'],
        'Latin & reggaeton' => ['Pop', 'Hip hop', 'Electronic'],
    ],
    // A family "is part of your taste" from this profile weight.
    'liked_family_min' => 0.04,

    'recommended' => [
        // No fixed limit: everything that fits is listed (paging is the UI's job).
        'always_include' => ['following', 'interested'], // known artists, whatever the score
        // The music score needs several pieces of evidence to get here: a few
        // genre tags you listen to, or fewer plus Last.fm links / your ratings.
        'min_fit' => 0.495,
        // Data confidence (genre information, or documented Last.fm links).
        // Too little: not recommended - possibly a "limited data" wildcard.
        'min_confidence' => 0.5,
        // Ordering: penalty per earlier pick of the same kind (same lead
        // artist link) / same artist. Variation without genre quotas.
        'diversity_penalty' => 0.04,
        'same_artist_penalty' => 0.13,
    ],

    // No cap and no quota per family: every real bet is shown.
    'wildcards' => [
        'min_fit' => 0.29,         // still a qualified bet, not a random record
        'max_fit' => 0.592,        // above this it is simply a recommendation
        'min_confidence' => 0.5,   // a bet needs solid data
        // New: a family you (nearly) never play...
        'novel_family_max' => 0.015,
        'min_new_family_share' => 0.35,
        // ...or a mix of families that are not relatives (see related_families)
        // and that none of your artists has. Plus a SPECIFIC bridge back: tag
        // overlap with one of your artists or a Last.fm link at least this
        // strong, or a specific genre you like. The same bar lets a release with
        // too little data be a "limited data" wildcard.
        'min_neighbour_bridge' => 0.35,
        'max_outside' => 0.60,     // not completely unrelated
        'new_artist_years' => 2,   // "new band" if active since at most this many years
    ],

    // Artist interest, see MusicRadar\Recommend\ArtistInterest: how much you
    // have played the credited artists on Last.fm (your whole library, not only
    // the profile artists). Kept apart from the music score: the score shown is
    // still the music fit. Following/Interested artists are always included as
    // before; "listened" artists only get help when the music is a near miss.
    'interest' => [
        'enabled' => true,
        // Plays per period on a log scale up to 'ref' plays (= full value);
        // recent periods count most, so old plays fade.
        'periods' => [
            'overall' => ['weight' => 0.20, 'ref' => 150],
            '12month' => ['weight' => 0.45, 'ref' => 40],
            '3month' => ['weight' => 0.35, 'ref' => 12],
        ],
        // A few plays are thin evidence: the value is multiplied by
        // n / (n + plays_prior), so 1 play counts a quarter, 10 plays ~ 3/4.
        'plays_prior' => 3,
        // How sure we are it is the same artist (see ArtistInterest).
        'certainty' => ['confirmed' => 1.0, 'mbid_only' => 0.8, 'name_only' => 0.5, 'conflict' => 0.0],
        'listened_min' => 0.10,      // "Listened" from this value (label and reason only)
        // Help into Recommended for You: decision score = fit + assist_weight *
        // interest. Only when the music itself fits at least assist_min_fit -
        // plays alone never recommend a release.
        'assist_weight' => 0.0,   // switched off: plays are in the score itself (personal blend)
        'assist_min_fit' => 0.197,
        'disliked_max_stars' => 2.0, // rated this artist this low on average: no help
        // A release by a "listened" artist (matched with certainty) that is neither
        // recommended nor a bet can be a wildcard of its own ("an artist you know"):
        // fit + assist_weight x interest >= wildcards.min_fit, with genre data
        // (data confidence >= listened_min_confidence) and not mostly outside
        // your taste. Never makes Recommended more generous.
        'listened_wildcards' => true,
        'listened_min_confidence' => 0.2,
    ],

    // Dynamic blend (RecommendationEngine): score = (1 - w) x music + w x personal.
    // E = your ratings of the artist + plays_weight x its listening value (0..1),
    // w = E / (E + prior): 1 rating = 25 %, 3 = 50 %, 9 = 75 %; plays alone at most 33 %.
    // Personal = the mean of your ratings (1 star = 0 .. 5 stars = 1; Not
    // interested = 1 star); plays count as plays_opinion with weight
    // plays_weight x value / (1 + ratings), so ratings take precedence.
    'personal' => [
        'prior' => 3,
        'plays_weight' => 1.5,
        'plays_opinion' => 0.7,
    ],
    // Your own star ratings, see MusicRadar\Recommend\RatingSignal. Each part is
    // shrunk toward neutral by its "prior", so one rating only nudges and the
    // effect grows with consistent ratings:
    //   artist  other releases by the same artist - added to the score
    //   tag     per genre tag (tag_prior) - moves how much you like the tag
    //           inside the genre signal, so tags are never rewarded twice
    //   related per unrated sub-genre (related_prior): ratings of related
    //           specific tags of the same family, by how often the two go
    //           together on releases - half of it moves a specific sub-genre
    //           that is neither in your profile nor rated itself (youth crew
    //           after good hardcore punk ratings), as such a tag gets half of
    //           a liked family (model.family_fallback)
    // The older "similar" and "genre" parts are off (0): they repeated the tags.
    // "Not interested" hides that release and counts as a 1-star rating of the
    // artist only (no tags, so genres are untouched); it is not your own rating;
    // "Save for later" is ignored by the engine.
    'ratings' => [
        'enabled' => true,
        'stars' => [1 => -1.0, 2 => -0.5, 3 => 0.1, 4 => 0.5, 5 => 1.0],
        'artist_weight' => 0.263,  // other releases by the same artist
        'artist_prior' => 1,
        'tag_prior' => 10,         // per genre tag: one 5-star rating moves it 1/11, five consistent ones 1/3 -
                                   // one rating mainly moves the artist (artist_weight), not a whole genre
        'related_prior' => 10,     // like tag_prior; only half of it is used (indirect evidence):
                                   // one 5-star rating of a closely related tag moves a sub-genre 1/22 at most
        'similar_weight' => 0,
        'similar_prior' => 4.0,
        'similar_min' => 0.5,
        'genre_weight' => 0,
        'genre_prior' => 5,
    ],
];
