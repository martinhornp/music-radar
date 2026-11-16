<?php

// Settings for the incremental discovery pipeline (MusicRadar\Discovery\DiscoveryPipeline).
// Free to run: MusicBrainz (max 1 request/s) + Last.fm API, state in a local store.

return [
    // Which first-release days make up the pool.
    'window_days' => 30,

    // Re-collect pool days to catch releases added late to MusicBrainz and
    // changed data - but not every day: the newest days often, older ones less.
    'recent_days' => 7,
    'recollect_recent_hours' => 20,
    'recollect_older_hours' => 72,
    // Expanded editions (CandidateFinder::readEditions) are searched from this day on.
    'editions_from' => '2026-11-13',

    // Known (followed/interested) artists are checked directly at most this often.
    'known_artists_every_hours' => 20,

    // Artist data from MusicBrainz is fetched in batches and refreshed after N days.
    'artist_batch' => 50,
    'artist_refresh_days' => 30,

    // Extra Last.fm data only where it matters: known artists, releases that
    // already look relevant, releases by artists you have clearly listened to
    // (artist interest, see 'interest' in recommender.php) and releases with
    // no genre data at all.
    'lastfm_min_prescore' => 0.20, // music-score scale (see recommender.php)
    'lastfm_min_interest' => 0.30,
    'lastfm_refresh_days' => 30,

    // Last.fm Similar Artists (stage 7): each artist once, refreshed after
    // similar_refresh_days; at most similar_per_day artists a day (the first
    // collection spreads over several nights); Last.fm unreachable: the stage
    // pauses for similar_backoff_hours while the rest of the import goes on.
    'similar_refresh_days' => 60,
    'similar_per_day' => 800,
    'similar_backoff_hours' => 6,
    'similar_limit' => 250,

    // An item that failed this many times is paused (it stays listed under
    // failures) and only retried after the pause - so one broken item does not
    // use up every short run.
    'max_attempts' => 3,
    'failure_pause_hours' => 24,

    // Where your ratings / "Not interested" come from (FeedbackSet::fromSource):
    // the website's API, as the feedback is stored in the database on one.com,
    // which is not reachable from outside. "db" = the configured database
    // (when the pipeline runs on the server), "none" = ignore feedback.
    'feedback_source' => 'https://musik.hornpedersen.dk/api/feedback',

    // Feed rules (no limit on Recommended, max Wildcards) are in recommender.php.
];
