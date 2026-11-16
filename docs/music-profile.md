# Music profile & artist identification

Current status: the profile is stored in MariaDB and refreshed weekly in
production; see [daily-import.md](daily-import.md). The active artist levels
are Interested and Listened. Following is not an implemented user feature
and is not used in the current setup. The code retains support for the
`following` value and manual overrides, but that is not an active follow function.

The schema examples, proposed tables and future-work sections below describe
the original design, not the current production architecture.

Goal of Martins Music Radar: **never miss a new album or EP that matters.**
This document describes the data foundation for that: who the listener is
(music profile), which artists are who (MusicBrainz identity), and which
artists to watch (follow list).

## Pipeline

```
Last.fm top 100 artists (all-time, 12 months, 3 months)
   │  + Last.fm tags per artist  ──► TagClassifier (genres / meta / mood / noise)
   ▼
ArtistMatcher  ◄── MusicBrainz search (+ lookup of Last.fm's own MBID hint);
   │                confirmed matches from the previous profile are reused
   │                + api/data/artist-overrides.php (manual decisions win)
   ▼
FollowPolicy ──► following / interested / ignored
   ▼
ProfileBuilder ──► music profile (JSON, schema below)
```

Code: `api/src/LastFm`, `api/src/MusicBrainz`, `api/src/Taste`.

## Tools (CLI, inside Docker)

| Command | What it does |
|---|---|
| `docker compose exec php php /var/www/api/bin/music-profile.php` | Report: every artist with MusicBrainz ID, genres, match status, follow status and problems |
| `… music-profile.php --json` | The full profile as JSON |
| `… test-artist-matcher.php` | Offline tests of the matching, follow and rating rules |
| `… lastfm-genre-profile.php` / `lastfm-taste-snapshot.php` / `lastfm-top-artists.php` | Earlier Last.fm analysis tools |

All are read-only and unreachable over HTTP (`api/bin`, `api/data`, `api/src`
are denied by `.htaccess`). MusicBrainz responses are cached for 7 days in the
container's temp dir (outside the web root and the deploy package).

## Artist identification (MusicBrainz)

Last.fm identifies artists by name only, and several artists can share a name.
Last.fm's own MusicBrainz ID is a hint, not proof: for "SYL" it points to
Strapping Young Lad. So we match against MusicBrainz ourselves.

**Rule: rather "needs review" than a wrong artist.**

Candidates are MusicBrainz artists whose *name* equals the Last.fm name
(normalised: case, Æ/Ø/Å, "The", punctuation). Artists that only carry the
name as an *alias* are used only if no artist actually has the name, and
never confirmed automatically.

Evidence per candidate:

| Evidence | Points |
|---|---|
| Last.fm's MBID points to it | +2 |
| Genre tags share a family with the Last.fm tags | +2 |
| Genre tags in a related family (e.g. punk vs hardcore) | +1 |
| Country matches a Last.fm nationality tag | +1 |
| Genres clearly contradict | −3 (conflict) |
| Country contradicts | −2 (conflict) |

Last.fm tags are *not* used as evidence when they look like several artists
mixed together (heavy styles and pop both ≥ 20 %, e.g. "Speed").

Decision:

- **confirmed** – one candidate with the name, ≥ 1 point and no conflict; or
  several same-name candidates where the genres single out one (it agrees,
  no other one agrees or is related). Last.fm's MBID must not point elsewhere.
- **needs_review** – anything else with candidates. Candidates are listed,
  ranked by evidence and by how well their genres fit the listener's profile
  (ranking only - never used to confirm).
- **not_found** – no MusicBrainz artist with that name.
- **manual / manual_none** – decided in `api/data/artist-overrides.php`.

Every match stores `source`, `confidence` (high/medium/low/none), `evidence`
and `problems`, so the reason for each decision is visible.

### Manual corrections

`api/data/artist-overrides.php`, keyed by Last.fm name:

```php
'Speed' => ['mbid' => '4ccddb42-9be6-46d8-822c-d9974962abec', 'note' => 'The Australian hardcore band'],
'Some Artist' => ['mbid' => false],          // no MusicBrainz entry - stop asking
'Turnstile' => ['level' => 'following'],     // you follow it
'Kim Larsen' => ['level' => 'ignored'],      // stop watching
```

Later this becomes an admin screen behind login, writing to the same model.

## Active artist levels: Interested and Listened

- **Interested:** every artist in the Last.fm top 100 per period, plus artists
  retained from earlier profiles, identified by MusicBrainz ID. Eligible releases
  with a confirmed date are included regardless of score; Not interested still
  hides a release. An own 1–2-star rating blocks this specific release before
  Interested inclusion; unrated releases and 3–5-star ratings retain the guarantee.
  Other releases by the same artist keep their guarantee. This priority fix takes
  effect on recalculation after deployment; see [feedback.md](feedback.md#release-rating-priority).
- **Listened:** computed per release from the broader Last.fm library, with
  recent plays weighted most, identity certainty and low artist ratings taken
  into account. Combined interest must reach 0.10. It can help a near miss into
  Recommended or qualify an "Artist you know" wildcard; it guarantees neither.
  It is not a stored watch level or a manually selected status.
- **Taste profile:** the whole top 100 per period (all-time, 12 months, 3
  months) shapes the musical profile - each artist once. In the genre profile
  each artist is weighted by its plays, damped: sqrt(plays / plays of that
  period's #1), with its best period (#1 = 1, about 0.5 for the median, about
  0.36 for #95-100); artists kept from earlier profiles get the lowest ranked
  weight. Within each listening layer (core, current, recent, dormant) the
  genres are this weighted average; the layers keep their weights, so recent
  listening still counts. Neighbour artists and Last.fm Similar Artists use
  every taste artist with its layer weight, as before.
- **Known limitation:** a genre tag counts as fully liked from a fixed profile
  weight of 0.015 (`liked_genre_ref`). A broader profile spreads the weight
  over more genres, so a narrow sub-genre you play through few artists (e.g.
  beatdown hardcore) can fall below it and count less. Not changed for now.

See [discovery-pipeline.md](discovery-pipeline.md#artist-interest) for the
current decision rules. There is no website action or API route for following
an artist. No artist currently has a `level` override in `artist-overrides.php`.

### Original watch-level design (technical background)

The following table and override examples describe code support, not active
user features. Its older "detect and evaluate" description for Interested
is superseded by the inclusion rule above; `monitoring.releases` still carries
that older name in the profile data.

Every artist we know from the listener is watched for new albums and EPs, on
one of two levels (`watch.level`):

| Level | How an artist gets there | New albums/EPs |
|---|---|---|
| **following** | Only by your explicit choice (`'level' => 'following'`) | **Always** included in "Recommended for You" ("You follow this artist") |
| **interested** | Automatically: every artist in your Last.fm top 100 (all-time, 12 months, 3 months - no difference between #1 and #100), plus artists kept from earlier profiles. All of them also form the musical taste profile (genres, "close to" artists, Last.fm links, Danish share; `lastfm.taste`), weighted by plays in the genre profile | **Always detected**, then evaluated against the profile and your feedback - never silently missed because you haven't followed yet |
| ignored | Only by your explicit choice | Not watched |

`monitoring.releases` says the same per artist: `always_include`,
`detect_and_evaluate` or `ignore`.

Nothing disappears automatically: `ProfileBuilder::retain()` keeps artists
from the previous profile that dropped out of the Last.fm top lists
(`lastfm.retained = true`, counted as dormant), and adds artists you follow
by hand even if they were never in your top lists. Locally:
`music-profile.php --previous=<earlier profile.json>`; in the database this
is simply "rows are never deleted automatically".

Strong, lasting Last.fm signals produce a **suggestion** to follow
(`watch.suggest_following`): top artist in all periods, all-time top 15,
this year's top 15, or top 30 all year. You approve by adding
`'level' => 'following'`.

An artist is only *detectable* once it has a confirmed (or manual)
MusicBrainz ID; otherwise `monitoring.blocked_reason` says why.

Recommended artists you don't know yet come later from the profile (similar
artists, genres) and are not added to these lists automatically.

## Music profile schema (v1)

```jsonc
{
  "schema_version": 1,
  "user": "MartinHornP",
  "generated_at": "…",
  "sources": { "lastfm": {…}, "musicbrainz": { "confirmed": 57, "needs_review": 9 } },
  "layer_weights": { "core": 0.35, "current": 0.30, "recent": 0.20, "dormant": 0.15 },
  "genres":   [ { "name": "hardcore", "family": "Hardcore", "weight": 0.148, "by_layer": { "core": 0.24, … } } ],
  "families": [ { "name": "Hardcore", "weight": 0.235, "by_layer": { … } } ],
  "attributes": { "countries": { "US": 27, "DK": 11, … }, "danish_share": 0.167 },
  "ratings": { "count": 0, "share_of_profile": 0, "model": "…" },
  "artists": [ {
    "name": "Knocked Loose",
    "lastfm": { "periods": { "overall": { "rank": 3, "plays": 2114 }, … }, "listening": "core", "mbid_hint": "…", "top_tags": […] },
    "musicbrainz": { "status": "confirmed", "mbid": "9ca10859-…", "confidence": "high", "source": "musicbrainz_search",
                     "evidence": […], "problems": [], "candidates": [], "country": "US", "musicbrainz_tags": […] },
    "genres": { "source": "lastfm", "weights": { "hardcore": 0.403, … }, "families": { "Hardcore": 0.645, … } },
    "country": "US", "country_source": "musicbrainz",
    "watch": { "level": "interested", "reason": "…", "source": "lastfm_core", "suggest_following": "top artist in all periods" },
    "monitoring": { "enabled": true, "releases": "detect_and_evaluate", "blocked_reason": null, "last_checked_at": null, "last_release_found_at": null, "last_error": null },
    "issues": []
  } ]
}
```

Listening layers: **core** (all three periods), **current** (last 3 months),
**recent** (last 12 months, not the last 3), **dormant** (only all-time).
Dormant keeps 15 % of the weight: quiet lately ≠ disliked.

Genre weights: within a layer every artist counts equally (no popularity
bias), each artist's genres are its Last.fm tag weights normalised to 1
(an artist can belong to several genres). Genre source per artist: Last.fm
tags, falling back to MusicBrainz tags when Last.fm's are missing or mixed;
artists with no reliable genre data are kept but don't shape the profile.

## Ratings

Stored in MariaDB and used by the recommendation engine – see
[feedback.md](feedback.md). (`RatingInfluence` here only reports the planned
share of ratings in the profile.)

## Proposed database tables (not created)

```sql
CREATE TABLE artists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lastfm_name VARCHAR(255) NOT NULL UNIQUE,
  mbid CHAR(36) NULL,
  match_status ENUM('confirmed','needs_review','not_found','manual','manual_none') NOT NULL,
  match_confidence ENUM('high','medium','low','none') NOT NULL,
  match_source VARCHAR(32) NOT NULL,
  match_evidence JSON NULL,          -- evidence, problems, candidates
  country CHAR(2) NULL,
  genres JSON NULL,                  -- {source, weights, families}
  updated_at DATETIME NOT NULL,
  INDEX (mbid)
);

CREATE TABLE user_artists (          -- per user, behind login
  user_id INT NOT NULL,
  artist_id INT NOT NULL,
  watch_level ENUM('following','interested','ignored') NOT NULL,
  watch_source VARCHAR(32) NOT NULL,  -- lastfm_core, retained, manual, …
  first_seen_at DATETIME NOT NULL,    -- rows are never deleted automatically
  listening JSON NULL,               -- periods, layer
  PRIMARY KEY (user_id, artist_id)
);

CREATE TABLE artist_monitoring (
  artist_id INT PRIMARY KEY,
  last_checked_at DATETIME NULL,
  last_release_found_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  consecutive_failures INT NOT NULL DEFAULT 0
);

CREATE TABLE music_profiles (
  user_id INT NOT NULL,
  generated_at DATETIME NOT NULL,
  schema_version INT NOT NULL,
  profile JSON NOT NULL,
  PRIMARY KEY (user_id, generated_at)
);
```

## Finding new albums and EPs (next step - not built yet)

For every **detectable** artist (following or interested, with a confirmed MBID):

1. Ask MusicBrainz for its release groups:
   `GET /ws/2/release-group?artist=<MBID>&type=album|ep&fmt=json&limit=100`
   (1 request/second; ~70 artists ≈ 1½ minutes).
2. Keep primary type **Album** or **EP**; skip secondary types *Compilation,
   Live, Remix, DJ-mix, Demo* (configurable); *Mixtape/Street* on its own is
   kept, since mixtapes are usually full new albums. An expanded edition of an
   older album (other title, more tracks than every earlier release) counts as
   new music on its own date; remasters do not. Singles are ignored.
3. A release group is "new" if its `first-release-date` is after the last
   check (or within N days on the first run). Store it; never drop it for
   scoring reasons. **Following:** always shown in "Recommended for You"
   ("You follow this artist"). **Interested:** always stored and evaluated;
   shown unless your feedback says otherwise (e.g. Not interested), and
   always findable.
4. Update `last_checked_at`; set `last_release_found_at` when something new
   appears; record `last_error` and count consecutive failures.
5. Health checks: flag artists not checked for > 48 h, repeated failures, and
   active artists with no release for a long time (possible wrong MBID).

Recommendations from non-followed artists (similar artists, genres) and
Wildcards are built on top of this, using the profile weights.

### Honest limits

- MusicBrainz is community-edited: brand-new or small releases can appear
  late or be missing; dates can be incomplete (year only).
- We will measure coverage before promising completeness: compare what
  MusicBrainz finds for followed artists against Last.fm/Tidal and the
  listener's own knowledge over a few weeks, then decide whether a second
  source is needed.
- Artists in `needs_review` / `not_found` cannot be monitored until resolved
  - the report lists them.
