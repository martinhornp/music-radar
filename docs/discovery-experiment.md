# Music discovery – rule-based engine (experiment v1)

Historical experiment, not the current production specification. Following
references describe the original design and retained code support; Following
is not an implemented user feature or used in the current setup. Current
artist levels are Interested and Listened; see [music-profile.md](music-profile.md)
and [PROJECT_CONTEXT.md](../PROJECT_CONTEXT.md).

Local experiment: can our own engine find new albums and EPs that fit the
listener's taste – including from artists they have never heard of?

**Hard requirement: no running costs.** Everything runs in plain PHP on our
own code with free data (MusicBrainz, Last.fm API). No paid AI models, no paid
APIs, no Composer dependencies. It can run on one.com later.

## Principles

- **Not a copy of other services.** Candidates come from MusicBrainz' catalogue
  of new releases. Last.fm's or Tidal's "similar artists" / recommendations are
  never used – not to pick candidates and not to justify a pick. When the
  engine says "close to Knocked Loose", that is our own comparison of
  documented tag profiles.
- **Only documented facts.** Every release has facts with ids and sources;
  every reason cites the fact ids it comes from. Nothing about how a release
  sounds is invented. Without genre information a release is "not assessable".
- **Popularity plays no role.** Play counts and listener numbers are not used.
- **Following artists are never filtered out.** Their releases are always in
  "Recommended for You". Releases from "interested" artists are always
  registered and assessed.

## Pipeline

1. **Candidates** – `Discovery/CandidateFinder`
   - Pool: release groups whose *first* release date is an exact day in the
     window, Album/EP, official, no secondary type (no live, compilation,
     remix, DJ-mix, demo, soundtrack) - except Mixtape/Street on its own,
     since mixtapes are usually full new albums. Expanded editions count too: a
     release in the window of an older album, titled differently and with more
     tracks than every earlier release (a deluxe with new songs), is new music
     on its own date; remasters are not. Reissues/remasters belong to older
     release groups and fall out; reissue-like titles are skipped too.
   - MusicBrainz quirks handled:
     - a search returns at most **500 hits** (offset + limit ≤ 500);
     - release groups with only a year/month ("2026") match *every* day of a
       date query (≈85 % of a normal day's hits).
     So the pool is read **adaptively**: per day, per type (album / EP), and
     when a query still has more than 500 hits it is split by artist-name
     range (`artistname:[* TO m]`, `[m TO *]`, then further halves). The
     ranges overlap only on boundary tokens and together cover every name
     (incl. non-Latin); duplicates are removed by id. Every page is read and
     only release groups dated **to the exact day** are kept. Unreachable hits
     (if a range can't be split further) are counted and reported.
     (v1 without name splitting reached 84 % of the pool; busy Fridays were
     cut off.)
   - **Genre-neutral by default** (`--random-share=1.0`): the candidates are a
     seeded uniform sample of the whole exact-dated pool – found broadly
     *before* relevance is judged. Profile-based genre-tag searches (`tag:x`)
     are still available with `--random-share < 1`, but they bias the pool
     toward what you already like (v1 used 40 % tags and over-represented
     hardcore).
2. **Known artists** – `Discovery/KnownArtistReleases`: each watched artist
   with a MusicBrainz ID is checked directly, independently of the sample.
   Their releases may also appear in the sample – the report counts those as
   duplicates.
3. **Release dates** – `Discovery/DateVerifier`: only a first release date
   documented **to the day** counts. Year/month-only dates are checked against
   the group's individual releases: `probable` (a release has a full date in
   the window) or `unconfirmed`. Both go to **"Needs date check"** – assessed
   musically so you can see if they are relevant, but never presented as a
   certain new release (not even for followed artists) until confirmed.
4. **Facts** – `Discovery/Enricher`: MusicBrainz release tags and artist data
   (type, area, start year, description, genres/tags); Last.fm artist tags,
   bio, album tags and album description via the API – only when Last.fm
   verifiably means the same artist (MBID match or it knows this exact album).
5. **Engine** – `Recommend/TasteModel` + `Recommend/RecommendationEngine`.

### Reading the report

The data overview separates the groups so the numbers add up:

| Row | Meaning |
|---|---|
| Broad sample | the genre-neutral candidates |
| + extra releases by known artists | known-artist releases *not* already in the sample |
| = unique releases | everything assessed (sample + extras) |
| Known-artist releases found / duplicates | how many were found and how many were already in the sample |
| no genre data | no genre information anywhere – not assessable |
| date not day | year/month-only – goes to "Needs date check" |
| ASSESSABLE | genre data **and** a confirmed release day |

(v1's "57 good / 32 partial / 15 none" added up to 104 because it counted the
100 sampled releases plus 4 extra known-artist releases together.)

`--compare=<earlier result.json>` adds "Changes vs previous run": counts,
source mix, genre breadth, and for every recommendation/wildcard whether it
was kept, dropped or added – and why (not in the new sample, date not
confirmed, moved between lists, ranked below the cut).

## How the engine judges a release

The release's genre tags (with the facts they come from) form a weighted tag
profile. It is compared to the `TasteModel`, built from the music profile:

| Signal | Meaning |
|---|---|
| `genre` | Cosine similarity to the layered genre profile. Specific tags ("beatdown hardcore") count more than broad ones ("rock"). |
| `family` | Affinity with the genre families you listen to (coarser). |
| `neighbours` | Tag profile close to concrete artists you listen to, weighted by listening layer (core 1.0 → dormant 0.5). Our own comparison, not anyone's similar-artists list. |
| `blend` | Combines two families you like; flags whether none of your artists has that mix yet. |
| `novelty` | Share of families/tags that are new to your profile. |
| `outside` | Share of families unrelated to your taste (penalty). |
| `danish`, `known` | Danish artist (documented area/tags), artist already in your history. |
| `rating` | Later: your ratings nudge genres (see `RatingInfluence`); "Not interested" excludes the artist. |
| `confidence` | From metadata quality and number of genre tags. |

`fit = Σ weight × signal − outside penalty`. All weights and thresholds are in
**`api/config/recommender.php`** – tune there, no code changes.

**Recommended for You** (no fixed limit – paging is the UI's job):
- Releases from **Following** and **Interested** artists are always included,
  whatever the score, and listed first. Each pick has an `origin`:
  `following`, `interested` or `discovery`.
- Every other release with `fit ≥ min_fit` and enough data
  (`confidence ≥ min_confidence`). A strong match with thin data
  (`fit ≥ limited_min_fit`, `confidence ≥ limited_min_confidence`) is kept too.
- Picks with thin data are marked `data_quality: limited` and their reasons say
  what is missing ("Limited data: only 2 genre tag(s)"). Nothing is filled in.
- Order: fit (thin data counts a little less), with a small penalty for each
  earlier pick of the same kind (same lead neighbour artist) or the same
  artist. Many releases in one genre can still be recommended – they are just
  interleaved with other strong matches. No genre quotas.

**Wildcards** – deliberate bets, few rather than many weak:
- something **really new**: ≥ `min_new_family_share` of the release is in
  families you (nearly) never play (profile weight < `novel_family_max`), **or**
  a mix of families that are not relatives (`related_families`) and that none
  of your artists has – so ordinary metalcore/hardcore never qualifies;
- **and** a specific bridge back: a concrete neighbour artist
  (`neighbours ≥ min_neighbour_bridge`) or a specific genre you like;
- fit between `min_fit` and `max_fit` (above that it is simply a
  recommendation), solid data, not mostly unrelated (`outside ≤ max_outside`).
- Angles: `genre-blend`, `new-band`, `neighbouring-genre`, `left-field`. Max 5,
  one per family.

Every pick lists its reasons with the cited facts, the signal values and the
data source – so you can see exactly why it was chosen. The report also shows
"near misses" to understand the thresholds.

## Running

```
docker compose exec php php /var/www/api/bin/discovery-experiment.php [options]
```

| Option | Default | |
|---|---|---|
| `--days=N` | 30 | first release date within the last N days |
| `--candidates=N` | 100 | candidates to collect |
| `--random-share=X` | 1.0 | share sampled uniformly from the whole pool (rest: profile genre tags - biased) |
| `--compare=FILE` | | compare with an earlier `result.json` |
| `--seed=N` | today | same seed → same sample |
| `--profile=FILE` | build fresh | reuse `music-profile.php --json` output |
| `--reuse=FILE` | | reuse releases from an earlier `result.json` – no data collection; for tuning `recommender.php` in seconds |
| `--data-only` | | collect + enrich only |

Output: `api/var/experiments/<run>/result.json` + `report.txt` (git-ignored,
denied over HTTP). First run ≈ 15–20 min (MusicBrainz allows 1 request/s);
MusicBrainz responses are cached for 7 days.

Tests: `docker compose exec php php /var/www/api/bin/test-discovery.php`.

## Limits of a rule-based engine

- **It only knows tags.** Two "hardcore" records can sound nothing alike; the
  engine can't hear production, tempo, vocals or mood. Taste nuance beyond
  tags (e.g. "melodic but not clean vocals") is invisible to it.
- **Tag coverage decides quality.** New and small releases often have few or
  no tags; their data then comes mainly from the artist, not the record. A
  debut with no tags anywhere can't be judged and is shown as such.
- **MusicBrainz bias.** Community-edited: coverage and tags skew toward the
  genres and regions its editors care about; ~16 % of the window's releases
  are unreachable via search; new releases appear with a delay.
- **Tag vocabulary.** Different spellings and very personal tags are partly
  normalised (`TagClassifier`), not perfectly. Family rules are hand-made.
- **Cold start for feedback.** Without ratings the profile comes from listening
  only; play counts mean familiarity, not necessarily love.
- **Wildcards are heuristics.** "Bridge + novelty" is a reasonable rule, but it
  can't judge whether a surprise is a *good* surprise.

## Improving quality without running costs

1. **Ratings and feedback** (the biggest lever): every rating adjusts genre and
   artist weights (`RatingInfluence`, gradual and capped); "Not interested"
   and low ratings teach what to avoid; accepted wildcards teach which bridges
   work. Store per user behind login.
2. **Tune weights on real outcomes:** log which recommendations got high
   ratings and adjust `recommender.php` (or fit weights automatically with a
   tiny regression in PHP – free, no external service).
3. **More free metadata:** MusicBrainz release *relationships* (producer,
   label, featured artists, members' other bands), labels as a signal
   (labels you like), Wikidata/Wikipedia genre statements via their free APIs,
   Cover Art Archive for the UI.
4. **Listening co-occurrence from your own history:** Last.fm *recent tracks*
   (your scrobbles) show which artists you play together – our own "similar
   artists", built from your behaviour, not Last.fm's algorithm.
5. **Optional local audio analysis** later (e.g. tempo/energy from preview
   files with an open-source library) – free, but heavy; only if tags prove
   too coarse.
6. **Better coverage:** a second free source for new releases (e.g. label RSS
   feeds or Bandcamp tags via official means, if available) to reduce the
   MusicBrainz bias.
