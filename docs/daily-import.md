# Daily import and the central database

Martins Music Radar runs on its own: every day it finds new albums and EPs,
enriches them, recalculates the recommendations with your Last.fm profile and
your ratings, and the site shows the new feed - no commits, exports or
deploys involved. Everything lives in the MariaDB database on one.com
(name and user in the GitHub Secrets `DB_NAME` / `DB_USER`).

The active artist levels are Interested and Listened. Following is not an
implemented user feature and is not used in the current setup. References to
Following below describe retained code/configuration support, not an active
follow function. Interested comes from the top-100 profile and retained artists;
Listened is computed per release from the wider Last.fm library, plays,
identity certainty and ratings. See [music-profile.md](music-profile.md).

## Architecture

```
GitHub Actions (free scheduler)            one.com (PHP 8.4 + MariaDB)
  daily-import.yml, 00:17/02:17/04:17 DK  ──POST──▶  /api/internal/import  (secret token)
    calls again until "done"              │ one ~20 s step per call
                                          ▼
                              ImportRunner ─▶ DiscoveryPipeline ─▶ MusicBrainz / Last.fm
                                          │        (stores everything in MariaDB)
                                          ▼
                              RecommendationEngine (profile + your ratings)
                                          ▼
                              recommendation run ─▶ activated atomically
                                          ▼
  browser ──GET /api/recommendations──▶ the active run (same JSON as before)
```

Why GitHub Actions starts it: one.com Begynder has no SSH and PHP requests
are time-limited; GitHub's scheduler is free (the import uses roughly 5–15
minutes a day of the 2,000 free minutes a month for private repositories)
and keeps a log of every run. The work itself happens on the server, next to
the database.

## Tables

All created automatically (`CREATE TABLE IF NOT EXISTS` only - nothing is
ever dropped or reset by code, imports or deploys).

| Table | Content |
|---|---|
| `releases` | every album/EP seen (MusicBrainz release-group id), title, type, first-release date, artist, MusicBrainz tags, origin (pool/known), watch level, date check, Last.fm album data, first/last seen, change hash, prescore |
| `artists` | MusicBrainz artist data (tags, country, area, start year) and Last.fm data (tags, bio, MBID) with fetch times and status |
| `slices` | which release day/type was collected when (re-collection schedule) |
| `failures` | errors per stage and item with attempt counter (retried, paused after 3) |
| `meta` | small state: when each known artist was checked, data version, … |
| `profiles` | the music profile (Last.fm + MusicBrainz), newest in use, last 10 kept |
| `release_artists` | every credited artist of each release, in credit order (collaborations) |
| `listening_artists` | your Last.fm artist library with plays (weekly), for the artist-interest signal |
| `recommendation_runs` | one row per calculation: status (building/active/superseded/failed), times, counts, the versions of data/feedback/profile/algorithm it was built from |
| `recommendation_items` | the feed entries of a run (the API contract) |
| `import_log` | one row per import call: time, duration, what it did, errors (redacted) |
| `release_feedback`, `release_feedback_log` | your ratings, Not interested, Save for later and their history (unchanged) |

Sources and data quality stay with the data: tags keep their source
(MusicBrainz release/artist, Last.fm artist/album), Last.fm data is only used
when it is verifiably the same artist, and the metadata quality is derived
from what is present.

## The daily cycle

Each call does the next due work within ~20 seconds and stops in a consistent
state; the next call continues:

1. **collect** – every exact-dated album/EP per day and type from MusicBrainz
   (genre-neutral, all pages). Days of the last 7 days are re-checked every
   20 h, older days of the 30-day window every 72 h – so releases registered
   late on MusicBrainz and corrected dates/metadata are picked up. Unchanged
   data is recognised by a hash and not processed again; MusicBrainz answers
   are cached. A day is only marked done when every page was read.
2. **known** – your Interested artists, each checked directly
   (one artist at a time, so it continues across calls).
3. **artists** – artist data, 50 artists per request, refreshed after 30 days.
4. **prescore** – only when collected data changed.
5. **lastfm** – extra Last.fm data where it matters (known artists, releases
   that look relevant, releases without genre data).
6. **recommendations** – when everything above is done and something changed
   (new day, new data, new profile, your feedback, or a new algorithm version),
   the engine calculates the feed with your current ratings from the database
   and publishes it as a new run. The algorithm version is a hash of the
   recommendation code, its settings and genre data (`ALGORITHM_FILES` in
   `ImportRunner`), so the first import call after such a deploy recalculates
   from the stored data - once per version, without extra MusicBrainz or
   Last.fm calls. Only when every item is stored does it become active (one
   transaction); the site keeps showing the previous run until then. A failed
   calculation is marked failed and never shown. The last 7 runs are kept.

Rules carried over unchanged: eligible Interested releases with confirmed dates
always included and marked, limited metadata marked, Not interested
only hides the release, Save for later is ignored by the engine, ratings
learn as described in [feedback.md](feedback.md).

**Weekly profile refresh** (`profile-refresh.yml`, Sundays 23:41 DK, before the night's imports): rebuilds your
taste profile from Last.fm + MusicBrainz on the GitHub runner (no time limit
there) and stores it via `POST /api/internal/profile`; artists that dropped
out of your Last.fm top lists are kept. Then it fetches your whole Last.fm
artist library (`api/bin/lastfm-library.php`, ~20 requests) and stores it via
`POST /api/internal/library` – not if it shrank by more than 20 %.

## Safety and robustness

- **Token** – the internal endpoints need the secret `IMPORT_TOKEN` in the
  `X-Import-Token` header (never in a URL, so never in access logs). Without
  it, or with a wrong one, they answer 404. They can only run import steps,
  read status/profile, store a profile or the Last.fm library and copy whitelisted migration tables –
  no free-form database access, no admin pages.
- **One at a time** – a database lock (scoped to this database) makes a second
  simultaneous call answer "busy".
- **Time limits** – each call checks the time before every MusicBrainz
  request; MusicBrainz retries are limited so a backoff cannot exceed the
  limit; 1 request/s to MusicBrainz and ~4/s to Last.fm.
- **Resuming** – all progress is stored after each item; an interrupted call
  loses at most the request in flight.
- **Failures** – logged per item without secrets (API keys, passwords and the
  token are redacted), retried on later calls, paused after 3 attempts for
  24 h. A call with new errors is not "done", so the loop retries first.
- **Feed** – never half-updated (see step 6); an earlier run can be
  re-activated.
- **Unreachable server** – some GitHub runner addresses get no answer from
  the host at all. The loop (`scripts/import-loop.php`) gives up connecting
  after 10 s (a call that is connected still has 170 s), retries 5 times and
  logs the phase that failed (DNS, TCP, TLS, timeout) and the runner's
  public IPv4. If every call failed before a request was sent, the workflow
  continues on a new runner - at most 3 runners in all, one after the other.
  HTTP, PHP and import errors fail at once, as before. Repeating a call is
  always safe: it continues from the stored state, under the lock.
- **Deploys** – only upload files (code, frontend). They never touch the
  database; the server config file is written from GitHub Secrets.

## Fallbacks and switches

If the import fails - for whatever reason - nothing the site depends on
changes:

- **The site** keeps serving the last successfully activated run. If the
  database has no run yet (before the migration) or cannot be reached, it
  serves `data/feed.json` (the current feed). Tested.
- **Feedback** lives in its own tables, which the import never writes to;
  ratings keep working, with release snapshots from the active run or
  `feed.json`.
- **Switches** (GitHub repository variables, no code change):

| Variable | Effect |
|---|---|
| `IMPORT_ENABLED=true` | turns on the scheduled daily import and weekly profile refresh (off until set; manual runs always work) |
| `FEED_SOURCE=file` + deploy | the site serves `feed.json` only, ignoring the database runs (emergency switch) |
| `IMPORT_BUDGET=<seconds>` + deploy | work per import call, if one.com's PHP time limit is lower than expected |

- **Rollback of one bad run**: an earlier run can be re-activated
  (`RecommendationStore::activate`).

**Before relying on one.com**, `GET /api/internal/diagnostics` (token) checks
outbound HTTPS to MusicBrainz and Last.fm, PHP's time and memory limits,
whether `set_time_limit` is allowed, the database version, and with
`?benchmark=1` a dry run of the feed calculation (time and memory, nothing
published). If one.com cannot reach MusicBrainz/Last.fm or the limits are too
tight, the import stays off and the site stays exactly as it is; the
collection would then have to run on the GitHub runner instead (not built -
a separate task).

## Monitoring

- **GitHub → Actions → "Daily import"**: every run, with a summary
  (recommendations, data, failures, services). A run fails – and GitHub sends
  an e-mail – if the import did not finish, a call failed, or MusicBrainz or
  Last.fm could not be reached at all.
- **`GET /api/internal/status`** (with the token): last call, last finished
  import, last error, active run and recent runs, row counts, failures by
  stage, profile and feedback counts. `artist_levels` counts artists per
  level from the stored data: Following and Interested (the stored profile),
  the taste profile (top 100), unique top-100 artists, Listened (stored Last.fm
  library artists that are not Following/Interested and played enough - the
  engine decides Listened per release, so this is the number that can make a
  release Listened) and when the profile was last refreshed.
  `top100_check.not_interested` lists any top-100 artist that is not
  Interested, with its MusicBrainz id (reported, never changed).

## Setup on one.com (once)

GitHub Secrets (values never in chat, Git or logs):

| Secret | Use |
|---|---|
| `DB_PASSWORD` | exists |
| `IMPORT_TOKEN` | protects the internal endpoints; written to the server by the deploy (set) |
| `LASTFM_API_KEY` | Last.fm on the server and in the weekly profile refresh (set) |

Deployment order (each step can stop without affecting the site):

1. Push → deploy. Writes `config.local.php` (database, token, Last.fm key).
   Nothing reads the new tables yet; the site serves `feed.json` as before.
2. `GET /api/internal/diagnostics` - can one.com reach MusicBrainz/Last.fm,
   what are the limits? Stop here if not.
3. Migration (below) - copies the data, verified by counts and fingerprints.
   The migrated feed becomes the active run: the site shows the same feed,
   now from the database.
4. `GET /api/internal/diagnostics?benchmark=1` - feed calculation on the
   server (time, memory).
5. First import, started manually (Actions → Daily import → Run), watched.
6. Set `IMPORT_ENABLED=true` - from then on it runs every morning.
7. After some stable days: remove `data/feed.json` as fallback (separate
   change).

## Migration of the existing data

`api/bin/migrate-to-mariadb.php` copies the local SQLite store (3,697
releases, 3,364 artists, 62 collected days, failures, state), the music
profile and the current feed (as the first recommendation run) - through
`POST /api/internal/migrate` for production, or directly into a database
locally. Rows keep their MusicBrainz ids and are written by primary key, so
it can be run again without duplicates. At the end every table's row count
and fingerprint (all keys + data hashes) are compared with the source; it
fails if anything differs. Feedback is not touched (checked before/after).

## Locally

```
docker compose exec -e DB_NAME=music_radar_test php php /var/www/api/bin/migrate-to-mariadb.php --target=db
docker compose exec php php /var/www/api/bin/test-import.php        # separate test database
docker compose exec -T -e IMPORT_TOKEN=... php php -- http://localhost < scripts/import-loop.php
docker compose cp scripts php:/tmp/scripts && docker compose exec php php /tmp/scripts/test-import-loop.php
```

The CLI tools `discovery-run.php` and `export-feed.php` still work against
the local SQLite store for experiments; production does not use them.

## Limits

- The import depends on GitHub's scheduler (runs can start some minutes
  late) and on MusicBrainz/Last.fm being reachable from one.com.
- `data/feed.json` is still the fallback while the database has no active
  run; it is removed once the database feed is verified in production.
- One shared MariaDB server: performance is fine for this size (a feed read
  is one indexed query), but heavy imports share it with other customers.
