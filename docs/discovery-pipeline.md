# Discovery pipeline – the whole pool, incrementally

Goal: find relevant new albums and EPs among **all** new releases – including
from completely unknown artists – instead of a random sample. Free to run:
MusicBrainz + Last.fm APIs and our own PHP engine. Designed to run daily on
one.com Begynder later; for now it runs locally.

Current production status is documented in [daily-import.md](daily-import.md).
The active artist levels are Interested and Listened. References to Following
below describe retained code support (`following`), not an implemented user
feature or an active level in the current setup. There is no follow action in
the website or API, and no current artist-level override enables it.

Code: `Discovery/DiscoveryPipeline`, `Discovery/DiscoveryStore`,
`Discovery/FactBuilder`, CLI `api/bin/discovery-run.php`.
Settings: `api/config/discovery.php` (pipeline) and
`api/config/recommender.php` (scoring).

## Stages

| # | Stage | What | Requests |
|---|---|---|---|
| 1 | collect | Genre-neutral: every exact-dated album/EP per day and type, all pages; queries over 500 hits split by artist-name range. Days are only re-collected when due. | ~2–15 per day/type |
| 2 | known | New releases of your Interested artists, checked directly per artist – also collaborations where they are credited second or later; year/month-only dates verified. Independent of any score. | 1 per artist: every Friday, otherwise after 48 h |
| 3 | credits | Releases stored before every credited artist was kept get their full credit (single-artist pool releases from what is stored, the rest from MusicBrainz, 50 per request; resumable). Then each release's watch level from **all** its credited artists. | ~1 per 50 releases, once |
| 4 | artists | MusicBrainz artist data (tags, country, area, start year, description) for 50 artists per request; refreshed after 30 days. | ~1 per 50 artists |
| 5 | prescore | Every release scored from MusicBrainz data alone. | none |
| 6 | lastfm | Extra Last.fm data (artist tags + bio, album tags + description) only for: known artists, releases by artists you have clearly listened to (artist interest ≥ 0.30), releases that already look relevant (prescore ≥ 0.20), and releases with no genre data at all. | ≤ 3 per release |
| 7 | similar | Last.fm Similar Artists for your profile artists (whole lists) and the window's artists (only links to artists in your Last.fm library are kept). By MusicBrainz id; else by exact name, confirmed when MusicBrainz' url relations link the artist to that Last.fm page. Each artist once, refreshed after 60 days, at most 800 artists a day (the first collection spreads over several nights; the feed is built meanwhile). Last.fm unreachable: the stage pauses 6 h, the import goes on. | 1-3 per artist (+1 MusicBrainz for name matches) |
| 8 | final | Full facts + artist interest + Last.fm links → engine → Recommended for You + Wildcards (no limits or quotas). | none |

Releases without genre data are not dropped early: they go to stage 6 to look
for supplementary information. If nothing is found they are listed as "not
assessable".

The final feed excludes:
- **duplicates** – one row per release-group id; plus one entry per artist +
  title (album/EP twins, double MusicBrainz entries),
- **reissues/compilations** – first-release date must be in the window
  (reissues belong to older release groups) and reissue-like titles are
  excluded,
- **unconfirmed dates** – anything not documented to the day goes to "Needs
  date check".

Eligible releases from Interested artists are always in "Recommended for
You" (once the date is confirmed, unless marked Not interested), first in the list and marked with their
origin - whichever position the artist has in the credit ("A & B", "A feat.
B", 3+ artists). The selection rules are described in
[discovery-experiment.md](discovery-experiment.md#how-the-engine-judges-a-release).

Result of the rules on the 30-day pool (25 Oct 2026): 357 recommended (2 from
Interested artists, 79 marked "limited data"), 5 wildcards.

## Nothing is fetched twice

`DiscoveryStore` keeps:

| Table | Purpose |
|---|---|
| `releases` | every release seen; its data compared → `new` / `changed` / `unchanged`; first/last seen; Last.fm album data (reset only if the title or first artist - what it was looked up by - changed) |
| `release_artists` | every credited artist of a release in credit order: MusicBrainz id, name as credited, the artist's own name, join phrase. Both the pool and the known-artist stage store the release's own MusicBrainz credit, so a collaboration never flips between "A & B" and "B" |
| `listening_artists` | your whole Last.fm artist library (weekly): plays all time / 12 months / 3 months and how its identity is known (profile id, Last.fm's id hint, or name only) |
| `artists` | MusicBrainz + Last.fm data per artist with fetch times |
| `slices` | which day/type was collected when (re-collect recent days every 20 h, older days every 72 h – catches late MusicBrainz additions and edits) |
| `failures` | errors per stage/item with attempt counter – retried next run, never stop the run; after `max_attempts` the item is paused for `failure_pause_hours` |
| `meta` | e.g. when known artists were last checked |

Locally SQLite in `api/var/discovery.sqlite` (git-ignored, never deployed).
The SQL is portable; on one.com the same tables can live in MariaDB (only the
upsert syntax differs, handled in `DiscoveryStore::upsert`).

## Running on shared hosting (later)

Constraints on one.com Begynder: no SSH, PHP requests have a time limit, and
MusicBrainz allows 1 request/second.

- **Time budget:** `run(today, budgetSeconds)` stops starting new work when
  the budget is used and leaves everything in a consistent state; the next
  call continues where it stopped. The feed is rebuilt from stored data after
  every call. E.g. `--budget=25` per invocation.
- **Lock:** one run at a time (`flock`); a second call exits immediately.
- **Errors:** logged per item and retried on later runs; an item that failed
  `max_attempts` times is paused for `failure_pause_hours` so it cannot use up
  every short run.
- **Tested:** a store copy with deleted days, artists and Last.fm data was
  completed in 11 calls of `--budget=10`; each call continued where the last
  stopped, re-collected days were read from the cache, and 25 releases that
  MusicBrainz had added since the first run were found. A call overruns its
  budget by ~3 s (the feed rebuild), so use a budget a few seconds below the
  host's limit.
- **Caching:** MusicBrainz responses are cached on disk (max age per use);
  artist and Last.fm data in the store.

How it could be triggered (to decide later):
1. one.com scheduled task / cron, if the plan has one (to be verified).
2. A free external scheduler or a scheduled GitHub Actions workflow calling a
   protected endpoint (secret token) a few times per night, each call doing
   ~25 seconds of work.

Expected daily load after the first fill: ~110 new releases/day → recent days
re-collected (~1 min), a few artist batches, Last.fm for the relevant/unknown
new ones (~1–2 min) – a handful of 25-second calls.

The **first fill** (30 days, ~3,500 releases) is the expensive part; it can be
done locally and imported, or spread over many calls.

## Running locally

```
docker compose exec php php /var/www/api/bin/music-profile.php --json > api/var/profile.json
docker compose exec php php /var/www/api/bin/discovery-run.php [--budget=25] [--compare=old/result.json,...]
```

Output: `api/var/experiments/<time>-pipeline/report.txt` and `result.json`
(stage timings, counts, feed with reasons and facts, near misses, bias check,
comparison with earlier runs).

Your ratings and "Not interested" are read at the start of every run from
`feedback_source` (default: the live site's `GET /api/feedback`), see
[feedback.md](feedback.md); `--feedback=db|none|URL` overrides it.

To publish the picks on the website, `api/bin/export-feed.php` turns the newest
`result.json` into `api/data/feed.json` (display fields only), served by
`GET /api/recommendations`. Nothing is recomputed.

## Artist interest

How much you have played the artists credited on a release – a signal of its
own (`Recommend/ArtistInterest`, settings `interest` in
`api/config/recommender.php`), kept apart from the music score:

| Level | Who | Effect |
|---|---|---|
| Interested | an artist from your Last.fm top 100 per period, or retained from an earlier profile, identified by MusicBrainz ID | eligible, confirmed-date releases always recommended unless marked Not interested or rated 1–2 stars; other releases by the artist retain inclusion |
| Listened | an artist from your Last.fm library | may lift a near miss into Recommended for You; never guarantees it |
| Unknown | none of the credited artists | – |

- **Every credited artist counts.** The strongest watch level wins; listening
  values are combined (1 − (1−a)(1−b)…).
- **Gradual, recent plays first.** Plays per period on a log scale up to a
  reference amount (all time 150, 12 months 40, 3 months 12), weighted 0.20 /
  0.45 / 0.35, and shrunk toward zero while there are only a few plays
  (× n / (n + 3)). No "10 plays ⇒ recommend" rule.
- **Identity.** Same MusicBrainz id as a confirmed profile artist, or Last.fm's
  id hint *and* the name agree: certain (× 1). Only the name agrees and
  Last.fm has no id: × 0.5. The name agrees but Last.fm points to another
  MusicBrainz artist (a namesake such as ALMA, LISA, BT): no interest. No
  MusicBrainz lookup per library artist.
- **Ratings limit listening help.** Artists you rated at most 2 stars on average
  contribute no listening value. This does not override Interested inclusion.
- **Listened is computed per release**, not stored as a watch level: the
  combined interest must reach 0.10 after identity certainty and artist ratings.
- **Decision, not score.** A Listened release can be recommended when
  fit + 0.197 × interest reaches 0.495, with data confidence at least 0.5 –
  the listening boost applies only when fit is at least 0.197. Own 1–2-star
  ratings block this route; wildcard selection happens first.
  The score shown stays the music fit; such a pick
  keeps the origin "discovery" (sorted by its score with the others) and says
  why: "You have played X (recently) on Last.fm" – without play counts, since
  the site is public.
- Not interested hides that release and counts as a 1-star rating of the
  artist only (artist part, never the genre tags); Save for later is ignored.

Evaluation on the 30-day pool (26 Oct 2026, same data and feedback, old vs
new code): 320 → 327 recommended (+7, none removed, wildcards unchanged); 38
releases by Listened artists; 7 namesakes recognised and ignored.

## Tag classification

Every tag from MusicBrainz and Last.fm is classified by `Taste/TagClassifier`
(lists in `api/data/tag-classes.php` and MusicBrainz' official genre list in
`api/data/musicbrainz-genres.txt`):

| Class | What | Used in the score |
|---|---|---|
| genre | on MusicBrainz' genre list, an extra genre, or built from a genre word ("metallic hardcore", "synthpunk") | yes |
| property | a documented musical property ("aggressive", "harsh vocals", "raw"), with dimension, source and level (artist / album) | no - stored for later |
| meta | nationality, decade, label | no |
| noise | charts, covers, sites, ratings, personal tags ("offizielle charts", "animal on cover", "i have this album") | no |
| unknown | anything else | no - kept in the source data |

Spelling variants merge ("neo-soul" = "neo soul", "psy-trance" = "psytrance").
The source data is stored unchanged and classified when it is used, so a new
rule applies to everything stored, without API calls - also the stored music
profile, which is re-classified when it is loaded.
`api/bin/tag-report.php` shows how the stored tags are classified and lists
the most common unknown tags (candidates for `tag-classes.php`).

### Data confidence

The music fit says how well the known genres match your taste; the data
confidence says how well that is supported. It never changes the fit - only
whether a thin match is shown (and marked "Limited data") and the order:

- confidence = sources (both MusicBrainz and Last.fm, or a text: 1; one
  source: 0.6) x amount of genre information / 2
- a specific genre counts 1, a broad one ("rock", "hip hop", "rap") 0.35, and
  a tag contained in a more specific one ("hardcore" next to "beatdown
  hardcore") adds nothing; spelling variants are one tag
- so two independent specific genres give full confidence, "rap, hip hop"
  alone gives 0.35 - such a release is recommended only as a strong match
  (limited data), as before for thin data

Evaluation on the same data, profile and ratings (26 Oct 2026): production 345
recommended; with the tag classification 416; with this data confidence 398 -
the 16 broad-only and most single-tag picks the clean-up added are gone, the
17 improved by merged spelling variants and niche releases with two specific
genres from one source stay.

## Recommended, Wildcard or not

| Category | When |
|---|---|
| Recommended for You | Interested (eligible, with a confirmed date, unless marked Not interested or rated 1–2 stars); otherwise music score (+ listening help) ≥ 0.495 with data confidence ≥ 0.5. The score needs several pieces of evidence to get there (see "Model v2" below) |
| Wildcard | A real bet: mostly a genre family you rarely play, or an unusual mix of families, with a specific bridge back to your taste - tag overlap with one of your artists or a Last.fm link ≥ 0.35, or a specific genre you like (score 0.29-0.59, solid data). No cap and no quota per family |
| Wildcard ("Artist you know") | Neither of the above, but by a Listened artist matched with certainty (not by name only): score + 0.197 x listening interest ≥ 0.29, genre data and not mostly outside your taste. Never makes Recommended more generous; your 1-2 stars and Not interested still win. Config `interest.listened_wildcards` |
| Wildcard ("Limited data") | Too little music data to recommend (confidence < 0.5), but a specific genre you like, a bet-level score and a concrete reason beyond the tags: a Last.fm link ≥ 0.35 to one of your artists, or your good ratings of the artist. Thin data alone is never a reason |
| Not recommended | Everything else - findable through search. Also any release you rated 1–2 stars yourself, including Interested releases. Rated releases remain in My Music |

The release-rating priority fix above takes effect on recalculation after deployment. See
[feedback.md](feedback.md#release-rating-priority) for the production example
and comparison on the same data.

Wildcards are chosen before Recommended, so a bet is never hidden as a weak
recommendation. Evaluation on the same data, profile and ratings (29 Oct 2026,
30-day window): Recommended 475 -> 420, Wildcards 5 -> 60; last 7 days 83 -> 78
and 2 -> 10. Hardcore, punk, metalcore, metal and post-hardcore unchanged.

"Artist you know" wildcards (30 Oct 2026, same data/profile/ratings): 31 days
Wildcards 61 -> 67, 7 days 4 -> 7; Recommended identical (454 and 45), all
earlier wildcards kept.

## Model v2: evidence, counted once

- **Genre** - one signal. Each genre tag counts once by how much it tells
  (`TagInformation`, learned from the window's releases when there are at least
  200: frequency x cohesion - do releases with the tag resemble each other on
  their other tags? - capped for broad tags and tags made of broad words only,
  e.g. "pop rock" ≤ 0.5). How much you like it: your profile; a sub-genre of a
  genre or family you like counts half; your ratings of releases with the tag
  move it (sum of rating signals / (ratings + 10)). Shrunk by "full information"
  (two typical specific tags): genre = liked information / (all information +
  full). Tag overlap with one of your artists (shared information / (shared +
  full)) is an alternative route - the larger counts, never both.
- **Last.fm Similar Artists** - links between the release's artist and your
  artists, both directions (`SimilarArtists`): MusicBrainz id or a
  MusicBrainz-confirmed page = certain, name only = half, autocorrected = not
  used. A name match linked to several different profile artists is more
  plausible: its certainty rises to at most 0.75 (never "confirmed"); each
  profile artist counts once, hubs count less, and the link being scored never
  supports itself. Relative match values, never "sounds like". A link counts
  by how much you play that profile artist: layer weight x (0.5 + 0.5 x
  play_weight) (`lastfm_play_floor`), and up to 1.5x when you rated the artist
  well (`lastfm_rating_boost` x its shrunk rating lean, positive only).
- **Music score** = 1 - (1 - genre)(1 - 0.4 x Last.fm): Last.fm alone stays
  below Recommended; no Last.fm data is never a penalty; agreeing sources add up.
- **Score** = (1 - w) x (music - outside penalty) + w x personal, 0..1
  (1 = the strongest possible match). Personal = the mean of your ratings of
  the artist (1 star = 0 .. 5 stars = 1, Not interested = 1 star); plays count
  as 0.7 with weight 1.5 x listening value / (1 + ratings), so ratings take
  precedence. w = E / (E + 3), E = ratings + 1.5 x listening value: nothing
  known = music only. Ratings of an artist teach the genre signal only for
  other artists' releases (counted once). Danish artist, known artist and the
  listening help have weight 0. Families and blends give no points (only for
  wildcards).
- **No genre information:** music = 0.4 x Last.fm, then the same blend; data
  confidence = 1 - (1 - 0.5 x Last.fm)(1 - w). Can be recommended on your own
  ratings/plays, never a wildcard; nothing known at all = not judged (no score).
- **Data confidence** = 1 - (1 - tag confidence)(1 - 0.5 x Last.fm), tag
  confidence = source quality x information / full.
- All thresholds and other terms are the earlier ones x 1/0.76 (max-to-max).

Comparison on the same snapshot (30 Oct 2026), profile and feedback (24
ratings): see `api/var/eval/analyze5.txt` locally. 31 days: Recommended 447 ->
353, Wildcards 65 -> 37; 7 days 41 -> 41 and 6 -> 4. Recommendations resting on
at most one specific tag 78 -> 16.
