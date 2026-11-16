# Direct TIDAL album links

Links become available after deployment and the first successful import check.
No TIDAL API, account integration, embedded player, autoplay or search fallback.
Recommendation scoring is unchanged.

## Publication rule

MusicBrainz release-group IDs identify albums in the radar, but a listening
link must come from a concrete **Official / Digital Media** release with a
TIDAL streaming relation. The editions must be a complete first page, and the
chosen album's editions must match the release's artist MBIDs and title, have
a non-empty track count and agree on track count and barcode. Typography may
differ; edition words are never stripped.

- **One TIDAL album:** it is used. Its date may differ from the release date,
  and its note ("Streaming Release", "Digital streaming version" ...) does not
  matter - except a note meaning other music (acoustic, live, instrumental,
  remix, demo, unplugged, karaoke, deluxe, expanded, stripped, a cappella,
  orchestral): then every edition of the release must carry it (the album *is*
  that version), otherwise it is another version and stays ambiguous.
- **Several TIDAL albums:** first those whose title differs ("(Deluxe)") are
  dropped, then albums released only for one country outside Europe (JP, CA,
  US ...; worldwide or unmarked editions stay) unless every album is such a
  release. Of the rest: "explicit", the unmarked main edition, "clean", "Dolby
  Atmos", then hi-res store versions ("hi-res", "Qobuz : 24-Bit"). Of several unmarked albums from different labels, the one whose label
  also releases the record in Europe (MusicBrainz country XE or a European
  country) - the US and EU licences are separate TIDAL albums. Anything else,
  e.g. two unmarked albums from the same label, stays ambiguous - never a guess.

Only HTTPS `tidal.com/album/<numeric ID>` and the corresponding `browse` and
`listen.tidal.com` forms are accepted as sources. Multiple URL spellings of the
same ID are deduplicated. Changing these rules bumps `TidalMatch::validationHash()`;
an ambiguous result from earlier rules is then due again at once (approved links
and missing results are not rechecked).

A unique relation that passes these checks is automatically **verified** and
publishes the button. There is no per-album approval list or manual review step.
If several editions share the same TIDAL ID, their artist credits, title, date,
track count and available barcodes must agree. Conflicts hide the button.
A deterministic concrete edition ID is retained as provenance.

This checks MusicBrainz metadata and its edition-level relation; it does not
independently verify TIDAL's current catalog, tracklist or Danish availability.
A wrong MusicBrainz relation that passes every identity check can still produce
a wrong link. Ambiguous IDs, incomplete pages and conflicting editions are
therefore rejected. No TIDAL pages are scraped and no search fallback is used.

## Queue and initial seven-day review

This revision replaces the daily quota and rolling catalog sweep. Only these
sources register work in the persistent `tidal_queue`:

1. Albums/EPs first discovered since activation (including its calendar day),
   released within the import's current 31-day window. All discoveries qualify,
   including releases that are not recommended. Future releases join when due.
2. The active run's Recommended and Wildcards released on the last seven calendar
   dates, inclusive (today minus six days through today). Selection joins the
   current `releases.first_release_date`, not import time or stale feed JSON dates.
3. Due retries and identity-related rechecks of registered entries. An older
   approved link with a changed identity can also register this concrete need.

First-attempt priority is 1 or 2; subsequent attempts are priority 3. Future
retry dates never block first attempts for new discoveries. Old unseen releases
are not registered. The daily import never looks at a release over 30 days old -
no retries and no first attempts, whether it came from the import or a backfill;
only the backfill workflow (a date stage) checks older releases.

The first active run and its seven-day cohort are recorded in `tidal_state` and
`tidal_queue.seed_run_id`. Later feed changes do not rewrite this initial cohort.
New recent recommendations are still eligible subsequently. `summary()` and the
private import response/workflow log expose totals, checked, verified, missing,
ambiguous, errors and pending. The local review uses a frozen public feed snapshot;
production will seed its then-active run on the first import after an approved deploy.

## One-time backfill of the active feed

Martin requested links for the complete active feed (run 29 at preparation time:
341 Recommended and 33 Wildcards, with 30 links already visible). This revision
registers the active run's released Albums/EPs once, including picks older than
seven days, in the existing queue at priority 2. It writes the frozen run ID to
`tidal_state.backfill_run_id` and marks its members with the same nullable field
in `tidal_queue`. The existing initial seven-day cohort is retained separately.

Registration and its completion marker are atomic and resumable. Existing valid
cache results are adopted without changing approvals, attempts, service pauses
or retry dates. Legacy manual-review candidates still need automatic validation.
New discoveries retain priority 1. Subsequent active runs cannot restart or
expand this backfill; the normal new-release/seven-day policy then continues.
Unselected historical releases never join this one-time cohort.

The private import response and workflow summary report `backfill` progress
separately from `initial`. The standard bounded importer, caching, matching and
429/5xx backoff apply unchanged. With the observed 30 links, at most 344 releases
need link discovery; existing fresh negative results and cached responses reduce
the immediate number of MusicBrainz requests. No score or feedback data is written.

## Requested date stages

`scripts/backfill-tidal.ps1 -From YYYY-MM-DD` dispatches the separate **TIDAL backfill**
workflow (`tidal-backfill.yml`) with its required `from` input. Once deployed, later dates require
no code change, commit or deployment. Martin can simply request a cutoff date;
the assistant runs the script. A missing cutoff is rejected and can never fall
back to a full music import. The date is sent in the authenticated request body on every
resumed call and every runner attempt. Invalid calendar dates and future cutoffs
are rejected before work starts. No new public endpoint is introduced.

Selection includes every stored Album/EP with a complete release date from the
cutoff **through today, inclusive**, regardless of recommendation status. Future
releases keep the existing release-day policy. The server selects a dedicated
TIDAL-only execution path: it never runs music discovery, Last.fm, profile work,
recommendation calculation or feed publication. It needs no music profile or
recommendation tables. Only TIDAL cache/queue/state are written. It also avoids
the ordinary import's monitoring call and initial/full-feed queue seeding.
Daily import itself is unchanged. The two workflows share the existing worker
slot and database lock to avoid parallel MusicBrainz traffic.

Approved links are never automatically looked up again, in either regular import
or date stages. Old refresh deadlines and retained failed refresh results do not
requeue them. Identity changes still hide an unsafe link locally, but do not cause
another MusicBrainz lookup. A broader cutoff therefore does not repeat approvals.

Fresh missing, ambiguous and failed results retain their existing retry dates;
selecting the same or an earlier cutoff never resets them. Due unresolved results
can retry. New stage-only queue entries use priority 4, behind existing discovery,
recent-feed and retry work. Existing priorities are preserved. All requests keep
the shared MusicBrainz throttle, persisted service backoff and short PHP budget.

Only nullable `tidal_queue.requested_from` is added. It does not make the daily
import look at older releases: an unfinished older request is continued by the
next backfill run. This does not start recurring weekly scans of the historical
catalog. Progress is checkpointed per release;
date-stage registration is atomic and safely repeatable.

The private response and workflow summary report the cutoff, upper date, total,
approved links, missing relations, ambiguous results, errors, unchecked releases
and results waiting for their retry deadline. Approvals are counted separately
from the latest refresh outcome, matching button visibility. Scores and feedback
are not changed by TIDAL enrichment.

### Rechecking one status

The same workflow takes `check`: `new` (default, the stage above), or
`errors`, `ambiguous` or `missing`. Those recheck every release with that
status from the date on, whatever its retry date - each once per run: the
loop sends the run's start time (`tidal_since`) on every call, and a release
checked since then is not taken again, so the run ends by itself. Nothing else
is checked. From PowerShell: `scripts/backfill-tidal.ps1 -From 2026-10-01 -Check missing`.

## Time budget, cache and failures

`Links/TidalImport` runs only after the core feed is ready and published, under
the existing import lock. It registers the queue even if no network budget remains,
then returns pending work so the workflow can resume in its next short call.
Each call checks at most four groups and starts another only if at least 6.2
seconds remain: a 1.1-second gap plus the production client's five-second timeout
and a margin. The client also retains its normal MusicBrainz throttle. No parallel
requests and no daily quota are used. Each group normally requires one browse
request; a first attempt can reuse a cached answer without a network request.
Due retries and identity changes request fresh data after their backoff, so an
old negative response cannot be reused just as its retry becomes due. Matching
rules are unchanged.

While `TidalLinkStore::BACKFILL_RETRIES` is false (the current setting), a date
backfill only checks releases never checked before and is done when none are
left; retries happen in the daily import only. Set it to true once every
backfill is through.

Missing relations are checked again on day 3, 5, 7 and 29 after the first check, then never again; a release whose active score is below 0.35 gets no retries in the daily import, shown or not (TIDAL links rarely
reach MusicBrainz within a day); unresolved conflicts
are scheduled weekly while relevant. Approved links are excluded regardless of
stored retry dates. Technical errors and incomplete responses without approval
retry after one day. A failed request is recorded as `error`, never `missing`;
individual failures (including timeouts, 404 and malformed responses) and unsafe
matches do not stop the next release. Safe error codes are retained in
`tidal_links.last_error` and the private workflow output, without raw exceptions.

HTTP 429 and 5xx pause TIDAL requests globally: 30 seconds, doubling to at most
15 minutes for consecutive service failures, or longer when `Retry-After` requires
it. The pause is persisted in `tidal_state` and honored across PHP calls and
workflow restarts. A successful response resets the service-failure count. The
failed release waits at least one day (and at least the service pause), allowing
other due retries to progress once the service recovers. New discoveries still
outrank retries. Pending ready work is never reported as drained because one
request failed. The workflow waits between calls during backoff and reports
TIDAL errors separately from the core import.

Before each lookup, a two-minute reservation is persisted. A terminated request
therefore yields its unfinished item to other work until the reservation expires.
Completed results and queue progress are committed together, one release at a
time; initial-cohort registration is also atomic. No transaction stays open during
HTTP. A workflow stop loses no committed results. Infrastructure errors outside
an individual lookup explicitly defer optional work and appear as a workflow
problem while the published feed remains available. The existing workflow has a
separate 150-call/50-minute limit; unfinished work continues in a later scheduled run.

Only the optional link cache is migrated: three nullable fields (`identity_hash`,
`approved_identity`, `verified_at`) and two small queue/state tables are added.
No release, artist, recommendation or feedback table is altered by this feature.
The link cache uses the same `utf8mb4_unicode_ci` collation as the music tables.
This revision adds only nullable TIDAL fields: `last_error` to `tidal_links`,
`reserved_until` to `tidal_queue`, and `paused_until`/`service_failures` to
`tidal_state`. Existing approvals and music data are preserved.

## Approval versus refresh

An approval is bound to normalized title, Album/EP type, actual release date and
all credited artist MBIDs. Genre tags, scores, display-name changes, generic
`data_hash`, retry dates and source-file hashes are not approval criteria.

- Expired refresh dates, missing relations, incomplete pages and network failures
  preserve the last verified album/edition and approval identity.
- A changed identity immediately hides the old link on the next uncached read.
- A complete response containing conflicting IDs or editions revokes approval.
- An unknown release never gets a button from missing, partial or failed data.
- A stable semantic rule fingerprint replaces the whole-file fingerprint. Future
  incompatible identity rules require explicit migration, not blanket invalidation
  for comments or formatting changes.

Legacy verified rows are retained during rollout when their stored source hash
still matches the release. Import migrates that evidence to an identity hash;
subsequent irrelevant metadata changes no longer hide the link. Legacy entries
whose old identity cannot be established safely need revalidation. Candidates
are never adopted as approvals. Older unchanged approvals are retained without
creating a recurring historical sweep.

Feed, search and My Music attach links in a batch read. They do not create tables,
fetch MusicBrainz or rewrite feedback snapshots. Existing HTTP caching still applies.

## Local initial-review command

`api/bin/review-tidal-links.php --feed=<snapshot under api/var/eval>
--today=YYYY-MM-DD --out=<new report path under api/var/eval>` runs only on the CLI.
It writes an isolated SQLite database and report under the ignored evaluation
folder, never production. It takes the exact feed snapshot and seven-day dates,
obtains complete artist credits through the existing MusicBrainz batch lookup,
and uses the actual queue, matcher and importer. The public feed exposes only
its first artist MBID, so source credits must not be guessed from display names.

In production the normal importer already stores those credits: about one extra
MusicBrainz request per uncached release. The local study may need an additional
batch credit lookup (plus individual fallbacks if the search index omits IDs).

## Frontend

`TidalLink` is shared by album cards and the detail dialog. The card link sits
above the stretched detail button, so it is a separate action. Invalid or absent
IDs render nothing, including in search and My Music.

- Windows browsers: `tidal://album/ID`, approved by Martin for this feature.
  Chrome can require an external-app permission dialog.
- Android and other platforms: `https://tidal.com/album/ID`.
  Mobile app opening is not guaranteed and has not been tested on devices.
- No `play` route, autoplay parameter, timed redirect or search fallback.

Martin confirmed that the integrated website button works on Windows after
production deployment. Earlier address-bar tests opened the right album without
autoplay, both with TIDAL open and closed. Protocol support is observed in the
installed TIDAL app, not a public cross-version guarantee. Android device testing
is deferred at Martin's request. No application is launched by automated tests.

## Checks

`api/bin/test-tidal-links.php` uses a guarded `_test` database and fake MusicBrainz
responses. It covers matching, edition ambiguity, bad URLs, source invalidation,
identity changes, retry isolation, approval retention and feed/search/My Music
integration. `test-tidal-queue.php` covers new-discovery priority, exact seven-day
selection, the frozen initial cohort, more than 20 daily checks, request spacing,
bounded batches, exhausted budgets and legacy migration without historical backfill.
It also simulates individual failures followed by successful matches, HTTP
429/503/500 backoff and recovery, unsafe editions, request interruption, atomic
checkpoint/cohort rollback, repeated failures, and retention of approved links.
`test-import.php` covers optional enrichment progress and failure isolation.
`scripts/test-import-loop.php` checks backoff across server calls and explicit
reporting of deferred infrastructure failures using localhost fake servers.
`node scripts/test-tidal-link.mjs` renders the actual component with Windows,
Android and other user agents, checking URL choice, hidden uncertain links and
absence of autoplay/search fallback. It uses the existing React and TypeScript
dependencies, without a new test framework.
