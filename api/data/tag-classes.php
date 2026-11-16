<?php

// How tags are classified (see MusicRadar\Taste\TagClassifier). Extend here -
// no code changes needed. A tag is checked in this order:
//
//   noise     says nothing about the music (charts, covers, sites, ratings,
//             "i have this album"): matched by the patterns below and the
//             fixed word lists in TagClassifier
//   meta      true but not musical (nationality, decade, label, scene)
//   property  a documented musical property ("aggressive", "harsh vocals") -
//             stored apart from genres and NOT used in the score (yet)
//   genre     on MusicBrainz' genre list (musicbrainz-genres.txt), in the
//             extra genres below, or built from a genre word ("gothic
//             hardcore", "synthpunk")
//   unknown   everything else: kept in the stored source data for later
//             classification, but never counted as a genre
return [
    // Musical properties by dimension. A property only says something about
    // the level it was tagged on (artist / album / track).
    'properties' => [
        'energy' => ['aggressive', 'energetic', 'intense', 'brutal', 'punchy', 'manic', 'frantic', 'fast', 'slow', 'loud',
            'heavy', 'chill', 'mellow', 'calm', 'relaxing', 'upbeat', 'laid-back', 'soothing', 'party', 'workout'],
        'mood' => ['dark', 'sad', 'happy', 'melancholic', 'melancholy', 'angry', 'hateful', 'menacing', 'nihilistic',
            'pessimistic', 'depressive', 'introspective', 'romantic', 'dreamy', 'emotional', 'uplifting', 'euphoric',
            'passionate', 'serious', 'rebellious', 'suspenseful', 'ominous', 'anxious', 'playful', 'sombre', 'somber',
            'bittersweet', 'nostalgic', 'nostalgia', 'feelgood', 'summer', 'violence'],
        'sound' => ['chaotic', 'dissonant', 'melodic', 'noisy', 'dense', 'catchy', 'atonal', 'rhythmic', 'uncommon time signatures',
            'technical', 'groovy', 'repetitive', 'hypnotic', 'chugging', 'anthemic', 'complex', 'minimalistic', 'abrasive', 'sparse'],
        'vocals' => ['harsh vocals', 'shrieking', 'screaming', 'screamed vocals', 'clean vocals', 'male vocalist', 'male vocalists',
            'female vocalist', 'female vocalists', 'male vocals', 'female vocals', 'female fronted', 'female-fronted',
            'vocal', 'female vocal', 'male vocal', 'instrumental', 'spoken word', 'rapping',
            'vocaloid'], // Yamaha's singing synthesizer: synthesized vocals, not a genre (not on MusicBrainz' list)
        'production' => ['raw', 'lo-fi', 'lofi', 'polished', 'atmospheric', 'lush', 'distorted', 'layered', 'warm', 'cold'],
        'instrumentation' => ['acoustic', 'synth', 'synths', 'guitar', 'piano', 'strings', 'saxophone'],
    ],

    // Genres not (yet) on MusicBrainz' list that occur in our data.
    'genres' => ['alternative', 'indie', 'metallic hardcore', 'danish hip hop', 'danish rap', 'alt-pop', 'alternative rnb',
        'electro techno', 'synth-punk', 'indietronica', 'dance-punk revival', 'gothic hardcore', 'minimal', 'acid', 'garage',
        'crust', 'goth', 'fusion', 'neoclassical', 'chanson', 'world', 'video game music', 'uk bass'],

    // Documented abbreviations / other names of a genre => the genre.
    'synonyms' => ['hnw' => 'harsh noise wall', 'vgm' => 'video game music', '8-bit' => 'chiptune'],

    // Words that make a genre on their own or at the end of a compound
    // ("synthpunk", "slowcore", "deutschrap"), besides the genre families'
    // own words (TagClassifier::FAMILIES).
    'genre_suffixes' => ['core', 'punk', 'step', 'techno', 'house', 'metal', 'rock', 'pop', 'wave', 'gaze', 'hop', 'rap'],

    // True but not musical (besides the fixed lists in TagClassifier).
    'meta' => ['united states', 'united kingdom', 'england', 'netherlands', 'finland', 'scotland', 'austria', 'indonesia',
        'deutsch', 'girl group', 'boy group', 'pianist', 'eurovision', 'furry', 'virtual youtuber',
        // Scenes and sources, not styles: doujin = self-published (Japanese circles),
        // touhou = arrangements from a game series, anime = music from/for anime.
        'anime', 'doujin', 'doujin circle', 'touhou', 'touhou project', '東方project'],

    // Noise, by kind of tag (regular expressions on the lower-cased tag).
    'noise_patterns' => [
        'cover' => '/\bon cover\b|\bcover art\b/',
        'charts' => '/\bcharts?\b|\bwochen\b|\bbillboard\b/',
        'year in a phrase' => '/\b(19|20)\d\d\b/',
        'site' => '/\.(de|com|net|org|fm|co\.uk)\b|plattentests|pitchfork|rateyourmusic|\brym\b/',
        'rating' => '/\bstars?\b|\d+\s*\/\s*\d+|\brated\b|\brating\b|^ph_/',
        'personal' => '/\b(i|my|mine)\b|\bto listen\b|\bneed to\b|^try$|\blistened\b|\bwishlist\b|\bowned?\b|\bcollection\b/',
        'platform' => '/myspace|spotify|youtube|tiktok|soundcloud|apple music|deezer|yandex|lidarr/',
    ],

    // Not musical but true: record labels (also "meta").
    'meta_patterns' => [
        'label' => '/\brecords?\b|\brecordings\b/',
    ],
];
