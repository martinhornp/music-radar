# Ratings, Not interested and Save for later

Your feedback on releases is stored permanently in MariaDB and feeds the
recommendation engine the next time it recalculates the feed.

Code: `api/src/Feedback/` (storage, validation, bridge),
`api/src/Controllers/FeedbackController.php` (API),
`api/src/Recommend/RatingSignal.php` (learning), frontend
`components/FeedbackProvider.tsx`. Tests: `api/bin/test-feedback.php`
(database + API), `api/bin/test-discovery.php` (learning).

## The three kinds of feedback

| | Meaning | Effect on recommendations |
|---|---|---|
| **Rating 1–5** | "I've listened, this is what I think" | Teaches the engine (see below), from the next recalculation |
| **Not interested** | "Don't recommend this release" – *not* a judgement of the music | Hides that one release. Changes no score, no genre, no artist |
| **Save for later** | A bookmark | None |

A rating and Not interested exclude each other; Not interested also removes
the bookmark; saving removes Not interested. Server and UI apply the same
rules.

## Data model (MariaDB)

```
release_feedback      one row per (user_id, release_id)
  user_id             1 for now - ready for login later
  release_id          MusicBrainz release-group id (stable, not the feed position)
  rating              1-5 or NULL           (CHECK 1-5)
  not_interested      0/1                   (CHECK: not together with a rating)
  saved               0/1
  release_snapshot    the release as it was in the feed when first marked (JSON)
  created_at, updated_at

release_feedback_log  every change: field, old value, new value, time
```

- The **snapshot** keeps a release in My Music, re-ratable, and teaching the
  engine after it has left the feed. It is always taken from the server's own
  feed, never from what a browser sends. Its score is history (the model of
  the day you marked it) and is never shown as current - see below.
- A row with nothing left (no rating, not hidden, not saved) is deleted; the
  log keeps its history, so accidental or malicious changes can be undone.
- Schema: `MusicRadar\Feedback\Schema`. On the server the tables are
  created automatically the first time the API needs them (only `CREATE
  TABLE IF NOT EXISTS`; existing tables and data are never touched). Locally
  `php api/bin/db-schema.php --print` prints the SQL, `--apply` creates it.

## API

| | |
|---|---|
| `GET /api/feedback` | All stored feedback with snapshots: `{"items": [{releaseId, rating, notInterested, saved, updatedAt, release}]}` - `release` carries the current score (below) |
| `POST /api/feedback/{releaseId}` | Exactly one change: `{"rating": 1-5 \| null}`, `{"notInterested": true\|false}` or `{"saved": true\|false}`. Answers with the stored item |

POST is used rather than PUT because some shared hosts block PUT.

**The score in My Music is the current one.** Every published run also stores
its score for each release it assessed, picked or not (table
`recommendation_scores`, pruned with the runs). The API puts the active run's
view over the snapshot: its entry if it picked the release (score, pick,
reasons), its score if it only assessed it (shown as "Not recommended" with
the score), otherwise no score at all (outside the run's 31-day window, or
before the first run with the table). The snapshot in the database is not
changed.

## How a rating teaches the engine

Ratings are an extra signal next to the Last.fm profile (settings in
`config/recommender.php` -> `ratings`, code in `RatingSignal`):

1. **Same artist** (weight 0.263) - other releases by the artist you rated
   (MusicBrainz artist id), added to the score. The only part that can tell,
   say, Knocked Loose and Parkway Drive apart: their tags are nearly the same.
2. **Genre tags you rated** - per tag, sum of signals / (ratings with the tag
   + 10). Not added on top: it moves how much you like that tag *inside* the
   genre signal, so a tag is never rewarded twice. A tag you already like
   fully cannot go higher. One rating moves a tag by 1/11 and five consistent
   ones by 1/3: one rating mainly says something about the artist (part 1),
   a genre moves only with several ratings.
3. **Related sub-genres you have not rated** - for a specific sub-genre that
   is neither in your profile nor rated itself (youth crew, after good
   hardcore punk ratings): your ratings of related specific tags of the same
   family, each weighted by how related it is - the share of the
   sub-genre's releases in the window that also carry the rated tag - and
   shrunk by 10 imaginary neutral ratings (as the tag prior). Half of that
   moves the sub-genre (indirect evidence), as such a tag gets half of a
   liked family. Never through a broad tag ("punk", "electronic"), never for
   a broad tag, never on top of the tag's own ratings. One 5-star rating of a
   closely related tag moves a sub-genre by 1/22 at most.

Stars -> signal: 1★ -1, 2★ -0.5, 3★ +0.1 (listened, it was fine), 4★ +0.5,
5★ +1. Each part is shrunk toward neutral, so one rating only nudges and the
effect grows with consistent ratings; poor ratings pull down the same way.

Measured 31/10 on the same data (31 days, 3,954 releases, 24 ratings), part 3
against the model without it: Recommended 347 -> 358 (7 days: 39 -> 41),
Wildcards unchanged; Prevention - And Nothing's Mine 0.400 -> 0.422; mostly
techno sub-genres (one 5★ techno rating), crossover thrash / powerviolence
(hardcore punk ratings); hip hop unchanged; poor ratings still take 12
releases out of Recommended.

No ratings -> the engine behaves exactly as without them (tested).

**Honest limits.** "Related" and "similar" mean *genre tags that go
together* - nothing more. Two releases with the same tags look identical to
the engine, however different they sound. Reasons say which ratings count:
"Your ratings favour hardcore punk", "Your ratings of related genres favour
youth crew" or "You rated *X* by *Y* 1 star - counts against it".

## When ratings take effect

### Release rating priority

Fix of 31 October 2026 (effective on recalculation after deployment): an own 1–2-star rating is checked
before Interested inclusion. It blocks only that release from Recommended and
all Wildcard routes. Unrated releases and 3–5-star ratings keep the Interested
guarantee. Other releases by the artist keep their guarantee; the existing
artist/genre learning is unchanged. Scores are still calculated, feedback and
snapshots are kept, My Music still shows the release, and search still reads
the full stored release pool.

Before the fix, the engine assessed releases, removed ineligible dates and
exclusions (including Not interested), selected wildcards, then included known
artists before checking the release's own rating. All Wildcard routes already
rejected 1–2 stars. Not interested already overrode Interested. Save for later
is omitted from the engine's input and neither restores nor hides a release.

Confirmed before deployment through read-only production feedback: The Number Twelve Looks Like
You – *Howls From the Holographic Heart*, 2 stars, Interested / Recommended.

Comparison of commit `575145f` and the fix: same `music_radar_eval_now` database,
31 October 2026, `profile-split.json`, fixed live feedback snapshot (28 ratings,
3 Not interested), 3,954 assessed releases. This local profile/data snapshot
does not exactly reproduce the production totals.

| Window | Recommended before → after | Wildcards before → after |
|---|---|---|
| 7 days (25–31 October) | 38 → 38 | 2 → 2 |
| 31 days (1–31 October) | 340 → 339 | 35 → 35 |

Only *Howls From the Holographic Heart* changes category: Recommended → not
selected. Metalcore Recommended decreases 20 → 19 over 31 days; every other
genre family and all Wildcard family counts are unchanged in both windows.
No score, score part or other assessment signal changes. No unexpected category
changes were found.

Regression tests cover 1 and 2 stars, no rating, 3–5 stars, another release by
the same artist without tags, retained `following` code support, Not interested,
Save for later with/without ratings, and My Music after a release leaves the feed.

### Saving and recalculation

1. A click is saved immediately (if saving fails, the change is undone and a
   message is shown).
2. The UI updates straight away (My Music, stars on cards; Not interested
   hides the release from Discover).
3. **The ranking changes only when the engine recalculates the feed** – the
   feed on the site is a fixed export until then.

The recalculation (`api/bin/discovery-run.php`) loads the feedback through
`FeedbackSet::fromSource()`:

| Source | When |
|---|---|
| `https://musik.hornpedersen.dk/api/feedback` (default, `feedback_source` in `config/discovery.php`) | the pipeline runs on this PC – the database on one.com cannot be reached from outside, so it reads the live site's API |
| `db` | the pipeline runs on the server (later: daily import) and reads MariaDB directly |
| `none` | deliberately without feedback |

If the source cannot be read, the run stops instead of silently building a
feed without your ratings. What the engine got is saved in `result.json`
(`feedback_used`), so `export-feed.php` reproduces the same scores.

## Security – no login yet

**Anyone who finds the site can change the ratings, Not interested and
bookmarks.** That is accepted for a personal test site and can only be solved
properly with login/access control. Until then:

- Only these three fields can be written, one per request, validated
  strictly (id format, 1–5, true/false, max 1 KB); nothing else in the
  database is reachable.
- All SQL is parameterised; the database refuses invalid values (CHECKs).
- Writes are only accepted as JSON from the site itself (Content-Type +
  Origin check), so other websites cannot change feedback through a
  visitor's browser.
- Errors never show database details (logged on the server only).
- The database password lives only in the GitHub Secret `DB_PASSWORD` and in
  `config.local.php` on the server (written by the deploy, git-ignored, not
  in the package, not reachable over HTTP); it is never printed in logs.
- Every change is logged (`release_feedback_log`), so it can be restored.
- Not done: rate limiting, login. Reading `GET /api/feedback` is public (it
  shows which releases you rated).

## Local development

```
docker compose exec php php /var/www/api/bin/db-schema.php --apply   # dev database
docker compose exec php php /var/www/api/bin/test-feedback.php       # separate test database
```

One-time setup of the test database (`music_radar_test`, dropped and
recreated by the tests – never the dev database):

```
docker compose exec db mariadb -uroot -proot -e "CREATE DATABASE music_radar_test; GRANT ALL ON music_radar_test.* TO 'music_radar'@'%'"
```

## On one.com

The site uses the one.com MariaDB database (name and user in the GitHub Secrets `DB_NAME` / `DB_USER`; host
`localhost` - only reachable from one.com's own web servers). Nothing has to
be done by hand:

1. **Connection** – the deploy workflow step "Configure database on the
   server" (`scripts/sftp-config.sh`) writes `api/config/config.local.php`
   over SFTP: password from the GitHub Secret `DB_PASSWORD`; host, name and
   user are set in `.github/workflows/deploy.yml` (secrets of the same name
   override them). If the server has a `config.local.php` the deploy did not
   make, it stops instead of overwriting it.
2. **Tables** – created by the API on first use (the smoke test's first
   request after the deploy).
3. **Check** – the smoke test reads `GET /api/feedback` and sends an invalid
   write that must be refused.

To change the password: change it in the one.com control panel, update the
`DB_PASSWORD` secret, and deploy.
