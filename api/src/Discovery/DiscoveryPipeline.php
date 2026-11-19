<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

use MusicRadar\LastFm\LastFmClient;
use MusicRadar\LastFm\LastFmException;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\MusicBrainz\MusicBrainzException;
use MusicRadar\Recommend\ArtistInterest;
use MusicRadar\Recommend\RecommendationEngine;
use MusicRadar\Recommend\SimilarArtists;
use MusicRadar\Taste\ListeningLibrary;

/**
 * Incremental discovery over the WHOLE pool of new albums/EPs - no sampling.
 *
 *   1 collect     genre-neutral, per day/type, every page (CandidateFinder::readSlice);
 *                 days are re-collected only when due (recent often, older rarely)
 *   2 known       new releases of followed/interested artists, checked directly
 *                 (each artist when due, so it can continue across short runs) -
 *                 also collaborations where they are credited second or later;
 *                 year/month-only dates verified (DateVerifier)
 *   3 credits     every credited artist of releases stored before credits were
 *                 kept (MusicBrainz, 50 per request; resumable), and each
 *                 release's watch level from ALL its credited artists
 *   4 artists     MusicBrainz artist data in batches of 50 (one request each)
 *   5 prescore    every release scored from MusicBrainz data alone (no requests;
 *                 only when collected data changed since the last prescore)
 *   6 lastfm      extra Last.fm data only for known artists, releases that
 *                 already look relevant, releases by artists you have clearly
 *                 listened to, and releases with no genre data
 *   7 similar     Last.fm Similar Artists for your profile artists and the
 *                 window's artists (a daily share; never holds up the feed)
 *   8 final       full facts + artist interest + Last.fm links -> engine ->
 *                 Recommended for You + Wildcards
 *
 * Everything fetched is stored (DiscoveryStore), so a run only does what is
 * new or due. A time budget lets it run in short slices (shared hosting); a
 * lock prevents two runs at once; failures are logged per item and retried
 * later instead of stopping the run.
 */
final class DiscoveryPipeline
{
    private float $deadline = 0.0;
    private ?string $now = null;
    /** @var callable(string): void */
    private $log;
    private readonly ArtistInterest $interest;
    private array $stats = [];

    public function __construct(
        private readonly DiscoveryStore $store,
        private readonly MusicBrainzClient $mb,
        private readonly LastFmClient $lastfm,
        private readonly RecommendationEngine $engine,
        private readonly array $profile,
        private readonly array $cfg,
        ?callable $log = null,
        ?ArtistInterest $interest = null,
    ) {
        $this->log = $log ?? static function (string $m): void {
        };
        // Without a Last.fm library: watch levels from the profile only.
        $this->interest = $interest ?? new ArtistInterest($profile);
    }

    /**
     * @param int  $budgetSeconds  0 = no limit (local); e.g. 20 on shared hosting
     * @param bool $buildFeed      also build the feed (stage 6) from what is stored;
     *                             the server import does that separately (buildFeed())
     *                             once collecting is complete
     */
    public function run(string $today, int $budgetSeconds = 0, bool $buildFeed = true): array
    {
        $t0 = microtime(true);
        $this->deadline = $budgetSeconds > 0 ? $t0 + $budgetSeconds : 0.0;
        $this->now = date(DATE_ATOM);
        $from = $this->windowStart($today);
        $this->stats = ['window' => [$from, $today], 'budget_seconds' => $budgetSeconds, 'stages' => [],
            'failures_forgotten' => $this->store->forgetFailuresBefore($from)];

        foreach (['collect', 'known', 'credits', 'artists', 'prescore', 'lastfm', 'similar'] as $stage) {
            if ($this->outOfTime()) {
                $this->stats['stages'][$stage] = ['skipped' => 'time budget used up - continues next run'];
                continue;
            }
            $ts = microtime(true);
            $result = $this->{'stage' . ucfirst($stage)}($from, $today);
            $this->stats['stages'][$stage] = $result + ['seconds' => round(microtime(true) - $ts, 1), 'complete' => !$this->outOfTime()];
        }

        $this->stats['complete'] = !array_filter($this->stats['stages'], static fn ($s) => isset($s['skipped']) || (($s['complete'] ?? true) === false));

        $final = [];
        if ($buildFeed) {
            // Locally the feed is always (re)built from what is stored, even after a partial run.
            $final = $this->buildFeed($today);
            $this->stats['stages']['final'] = $final['stats'];
        }
        $this->stats['seconds'] = round(microtime(true) - $t0, 1);

        return ['stats' => $this->stats] + $final;
    }

    /** Stage 6 on its own: everything stored for the window -> engine -> feed. */
    public function buildFeed(string $today): array
    {
        $this->now ??= date(DATE_ATOM);
        $ts = microtime(true);
        $final = $this->stageFinal($this->windowStart($today), $today);
        $final['stats']['seconds'] = round(microtime(true) - $ts, 1);
        return $final;
    }

    private function windowStart(string $today): string
    {
        return date('Y-m-d', strtotime("$today -{$this->cfg['window_days']} days"));
    }

    /**
     * Remembers that collected data changed (new/changed releases, artist or
     * Last.fm data): a counter - unlike a timestamp it cannot miss a change
     * made in the same second as the last feed.
     */
    private function dataChanged(): void
    {
        $this->store->setMeta('data_changed_at', $this->now);
        $this->store->setMeta('data_version', (string) ((int) $this->store->meta('data_version') + 1));
    }

    // --- 1. collect ------------------------------------------------------------------

    private function stageCollect(string $from, string $to): array
    {
        $finder = new CandidateFinder($this->mb);
        $count = ['slices_due' => 0, 'slices_collected' => 0, 'slices_skipped_fresh' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'skipped_records' => 0, 'errors' => 0, 'paused' => 0,
            'rechecked' => 0, 'date_changed' => 0];
        $recentFrom = date('Y-m-d', strtotime("$to -{$this->cfg['recent_days']} days"));
        $paused = $this->paused('collect');

        // Newest days first: they matter most if the budget runs out.
        for ($d = $to; $d >= $from; $d = date('Y-m-d', strtotime("$d -1 day"))) {
            foreach (['album', 'ep'] as $type) {
                $hours = $d >= $recentFrom ? $this->cfg['recollect_recent_hours'] : $this->cfg['recollect_older_hours'];
                $slice = $this->store->slice($d, $type);
                if ($slice !== null && strtotime($slice['collected_at']) > time() - $hours * 3600) {
                    $count['slices_skipped_fresh']++;
                    continue;
                }
                if (isset($paused["$d/$type"])) {
                    $count['paused']++;
                    continue;
                }
                if ($this->outOfTime()) {
                    return $count;
                }
                $count['slices_due']++;
                $errors = [];
                // Stop between MusicBrainz requests when the time is up: an unfinished
                // day is collected again next call (pages already read come from the cache).
                $read = $finder->readSlice($d, $type, $hours * 3600, $errors, fn () => $this->outOfTime());
                if ($read['interrupted']) {
                    $count['interrupted'] = ($count['interrupted'] ?? 0) + 1;
                    return $count;
                }
                $this->store->transaction(function () use ($read, &$count): void {
                    foreach ($read['release_groups'] as $rg) {
                        $r = CandidateFinder::toRelease($rg);
                        if ($r === null) {
                            $count['skipped_records']++;
                            continue;
                        }
                        if ($this->laterEdition($r)) {
                            $count['unchanged']++;
                            continue;
                        }
                        $count[$this->store->saveRelease($r, 'pool', $this->interest->watch($r['artist']['credits'])['level'] ?? null, $this->now)]++;
                    }
                });
                // Stored releases of this day that MusicBrainz no longer lists here
                // (postponed or corrected date) - only from a complete read.
                if ($errors === [] && $read['over_limit'] === 0 && !$this->recheckMissing($d, $type, $read, $hours * 3600, $count, $errors)) {
                    $count['interrupted'] = ($count['interrupted'] ?? 0) + 1;
                    return $count;
                }
                if ($count['new'] + $count['changed'] + $count['date_changed'] > 0) {
                    $this->dataChanged();
                }
                if ($errors !== []) {
                    // What was read is kept, but the day only counts as collected
                    // when every page was read - otherwise it is retried.
                    $count['errors']++;
                    $this->store->fail('collect', "$d/$type", implode('; ', $errors), $this->now);
                    continue;
                }
                $this->store->saveSlice($d, $type, $read, $this->now);
                $this->store->clearFailure('collect', "$d/$type");
                $count['slices_collected']++;
                ($this->log)(sprintf('collect %s %-5s %4d exact-dated (%d hits, %d partitions)', $d, $type, count($read['release_groups']), $read['hits'], $read['partitions']));
            }
        }
        return $this->collectEditions($finder, $from, $to, $recentFrom, $paused, $count);
    }

    /**
     * Expanded editions of older albums (CandidateFinder::readEditions), per day
     * once its regular collection is done - on the same schedule, each day
     * resumable on its own.
     */
    private function collectEditions(CandidateFinder $finder, string $from, string $to, string $recentFrom, array $paused, array $count): array
    {
        $count['editions'] = 0;
        $from = max($from, (string) ($this->cfg['editions_from'] ?? $from));
        for ($d = $to; $d >= $from; $d = date('Y-m-d', strtotime("$d -1 day"))) {
            foreach (['album', 'ep'] as $type) {
                $hours = $d >= $recentFrom ? $this->cfg['recollect_recent_hours'] : $this->cfg['recollect_older_hours'];
                $last = $this->store->meta("editions:$d/$type");
                if (($last !== null && strtotime($last) > time() - $hours * 3600) || isset($paused["$d/$type/editions"])) {
                    continue;
                }
                if ($this->outOfTime()) {
                    return $count;
                }
                $errors = [];
                $known = array_fill_keys(array_column($this->store->releasesOn($d, $type === 'ep' ? 'EP' : 'Album'), 'id'), true);
                $editions = $finder->readEditions($d, $type, $known, $hours * 3600, $errors, fn () => $this->outOfTime());
                if ($editions === null) {
                    $count['interrupted'] = ($count['interrupted'] ?? 0) + 1;
                    return $count;
                }
                $this->store->transaction(function () use ($editions, &$count): void {
                    foreach ($editions as $rg) {
                        if (($r = CandidateFinder::toRelease($rg)) !== null) {
                            $count[$this->store->saveRelease($r, 'pool', $this->interest->watch($r['artist']['credits'])['level'] ?? null, $this->now)]++;
                            $count['editions']++;
                            ($this->log)("collect $d $type: expanded edition {$r['artist']['name']} - {$r['title']}");
                        }
                    }
                });
                if ($count['new'] + $count['changed'] > 0) {
                    $this->dataChanged();
                }
                if ($errors !== []) {
                    $count['errors']++;
                    $this->store->fail('collect', "$d/$type/editions", implode('; ', $errors), $this->now);
                    continue;
                }
                $this->store->setMeta("editions:$d/$type", $this->now);
                $this->store->clearFailure('collect', "$d/$type/editions");
            }
        }
        return $count;
    }

    /**
     * The release is stored as a later expanded edition of the same album (other
     * title, later date): the album's original release must not replace it.
     */
    private function laterEdition(array $r): bool
    {
        $old = $this->store->release($r['id']);
        return $old !== null && $old['first_release_date'] > $r['first_release_date'] && $old['title'] !== $r['title'];
    }

    /**
     * A release stored for this day that a complete read of the day no longer
     * lists has most likely moved (a postponed album, a corrected date) - or
     * the read just missed it (MusicBrainz' paging can skip a few of many
     * equal-scoring hits). The missing ones are looked up by id, up to 60 in
     * one request, and a release with another date is stored with its current
     * date - otherwise it would stay "out" on its old day forever. Same date:
     * nothing changes. Not found (deleted, or not indexed yet): left as it is.
     *
     * @return bool false when the time ran out (the day is read again next call)
     */
    private function recheckMissing(string $day, string $type, array $read, int $maxAgeSeconds, array &$count, array &$errors): bool
    {
        $listed = array_flip(array_column($read['release_groups'], 'id'));
        $missing = array_values(array_filter($this->store->releasesOn($day, $type === 'ep' ? 'EP' : 'Album'), static fn ($row) => !isset($listed[$row['id']])));
        foreach (array_chunk($missing, 60) as $rows) {
            if ($this->outOfTime()) {
                return false;
            }
            $count['rechecked'] += count($rows);
            try {
                $now = $this->mb->releaseGroupsByIds(array_column($rows, 'id'), $maxAgeSeconds);
            } catch (MusicBrainzException $e) {
                $errors[] = "$day $type recheck: " . $e->getMessage();
                continue;
            }
            foreach ($rows as $row) {
                $date = (string) ($now[$row['id']]['first-release-date'] ?? '');
                // An expanded edition: its album was first released earlier under another title.
                $edition = $date < $day && $row['title'] !== (string) ($now[$row['id']]['title'] ?? $row['title']);
                if ($date === '' || $date === $day || $edition) {
                    continue;
                }
                $this->store->saveRelease([
                    'id' => $row['id'], 'title' => $row['title'], 'type' => $row['type'], 'first_release_date' => $date,
                    'artist' => ['name' => $row['artist_name'], 'mbid' => $row['artist_mbid'], 'primary_name' => $row['primary_name'],
                        'credited_artists' => (int) $row['credited'], 'credits' => null],
                    'release_tags' => json_decode((string) $row['release_tags'], true) ?: [],
                ], $row['origin'], $row['watch_level'], $this->now);
                $count['date_changed']++;
                ($this->log)("collect $day $type: {$row['artist_name']} - {$row['title']} moved to $date");
            }
        }
        return true;
    }

    // --- 2. known artists ---------------------------------------------------------------

    private function stageKnown(string $from, string $to): array
    {
        $finder = new KnownArtistReleases($this->mb, $this->log);
        $verifier = new DateVerifier($this->mb);
        $dueBefore = time() - $this->cfg['known_artists_every_hours'] * 3600;
        // Friday: every artist once that day, however recently it was checked.
        $friday = ($this->cfg['known_artists_on_friday'] ?? false) && date('N', strtotime($to)) === '5';
        $paused = $this->paused('known');
        $count = ['artists' => 0, 'checked_artists' => 0, 'fresh' => 0, 'releases' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'errors' => 0, 'paused' => 0, 'remaining' => 0];
        $watched = array_values(array_filter($this->profile['artists'], KnownArtistReleases::watched(...)));
        $count['artists'] = count($watched);
        foreach ($watched as $i => $a) {
            $mbid = $a['musicbrainz']['mbid'];
            $last = $this->store->meta("known_checked:$mbid");
            if ($last !== null && ($friday ? date('Y-m-d', strtotime($last)) >= $to : strtotime($last) > $dueBefore)) {
                $count['fresh']++;
                continue;
            }
            if (isset($paused[$mbid])) {
                $count['paused']++;
                continue;
            }
            if ($this->outOfTime()) {
                $count['remaining'] = count($watched) - $i;
                break;
            }
            try {
                foreach ($finder->forArtist($a, $from, $to) as $r) {
                    if ($this->laterEdition($r)) {
                        continue;
                    }
                    $r['date_check'] = $verifier->verify($r, $from, $to);
                    $level = $r['artist']['credits'] !== null ? ($this->interest->watch($r['artist']['credits'])['level'] ?? $r['watch_level']) : $r['watch_level'];
                    $count[$this->store->saveRelease($r, 'known', $level, $this->now)]++;
                    $count['releases']++;
                }
                $this->store->setMeta("known_checked:$mbid", $this->now);
                $this->store->clearFailure('known', $mbid);
                $count['checked_artists']++;
            } catch (MusicBrainzException $e) {
                $count['errors']++;
                $this->store->fail('known', $mbid, "{$a['name']}: {$e->getMessage()}", $this->now);
            }
        }
        if ($count['new'] + $count['changed'] > 0) {
            $this->dataChanged();
        }
        if ($count['remaining'] === 0) {
            $this->store->setMeta('known_checked_at', $this->now);
        }
        return $count;
    }

    // --- 3. credited artists ----------------------------------------------------------

    /**
     * Releases stored before every credited artist was kept get their full
     * MusicBrainz credit: a single-artist pool release from what is stored
     * (exact - the pool always stored the credited name), anything else
     * (collaborations, known-artist releases whose artist was replaced) from
     * MusicBrainz, 50 per request. Resumable: a release counts as done once
     * its credit is stored. Then every release in the window gets its watch
     * level from all its credited artists (and the current profile).
     */
    private function stageCredits(string $from, string $to): array
    {
        $count = ['missing' => 0, 'from_store' => 0, 'from_musicbrainz' => 0, 'corrected' => 0, 'requests' => 0, 'not_found' => 0, 'errors' => 0, 'paused' => 0, 'levels_updated' => 0];
        $paused = $this->paused('credits');
        $todo = $this->store->releasesWithoutCredits(100_000);
        $count['missing'] = count($todo);
        $fetch = [];
        $this->store->transaction(function () use ($todo, &$fetch, &$count): void {
            foreach ($todo as $row) {
                if ($row['origin'] === 'pool' && (int) $row['credited'] === 1) {
                    $this->store->saveCredits($row['id'], [['position' => 0, 'mbid' => $row['artist_mbid'], 'name' => $row['artist_name'],
                        'artist_name' => $row['primary_name'], 'joinphrase' => '']]);
                    $count['from_store']++;
                } else {
                    $fetch[] = $row;
                }
            }
        });
        $fetch = array_values(array_filter($fetch, static fn ($r) => !isset($paused[$r['id']])));
        $count['paused'] = $count['missing'] - $count['from_store'] - count($fetch);
        foreach (array_chunk($fetch, (int) $this->cfg['artist_batch']) as $batch) {
            if ($this->outOfTime()) {
                break;
            }
            try {
                $found = $this->mb->releaseGroupsByIds(array_column($batch, 'id'));
                $count['requests']++;
            } catch (MusicBrainzException $e) {
                $count['errors']++;
                foreach ($batch as $row) {
                    $this->store->fail('credits', $row['id'], $e->getMessage(), $this->now);
                }
                continue;
            }
            foreach ($batch as $row) {
                $rg = $found[$row['id']] ?? null;
                if ($rg === null && !$this->outOfTime()) {
                    // Not in the search index (yet): look it up on its own.
                    try {
                        $rg = $this->mb->lookupReleaseGroupCredits($row['id']);
                        $count['requests']++;
                    } catch (MusicBrainzException $e) {
                        $count['not_found']++;
                        $this->store->fail('credits', $row['id'], $e->getMessage(), $this->now);
                        continue;
                    }
                }
                if (($rg['artist-credit'] ?? []) === []) {
                    continue;
                }
                $artist = CandidateFinder::artist($rg['artist-credit']);
                if ($artist['name'] !== $row['artist_name'] || $artist['mbid'] !== $row['artist_mbid']) {
                    $count['corrected']++;
                    ($this->log)("credits: {$row['artist_name']} -> {$artist['name']} ({$row['title']})");
                }
                $this->store->setArtist($row['id'], $artist);
                $this->store->clearFailure('credits', $row['id']);
                $count['from_musicbrainz']++;
            }
        }

        // Watch level from every credited artist - also when the profile changed.
        $rows = $this->store->releasesInWindow($from, $to);
        $credits = $this->store->credits(array_column($rows, 'id'));
        $this->store->transaction(function () use ($rows, $credits, &$count): void {
            foreach ($rows as $row) {
                if (!isset($credits[$row['id']])) {
                    continue;
                }
                $level = $this->interest->watch($credits[$row['id']])['level'] ?? null;
                if ($level !== $row['watch_level']) {
                    $this->store->setWatchLevel($row['id'], $level);
                    $count['levels_updated']++;
                }
            }
        });
        if ($count['from_store'] + $count['from_musicbrainz'] + $count['levels_updated'] > 0) {
            $this->dataChanged();
        }
        return $count;
    }

    // --- 4. artist data in batches ------------------------------------------------------

    private function stageArtists(string $from, string $to): array
    {
        $stale = date(DATE_ATOM, time() - $this->cfg['artist_refresh_days'] * 86400);
        $todo = $this->store->artistsNeedingMusicBrainz($from, $to, $stale);
        $count = ['needed' => count($todo), 'requests' => 0, 'found' => 0, 'single_lookups' => 0, 'not_found' => 0, 'errors' => 0, 'paused' => 0];
        $paused = $this->paused('artists');
        foreach (array_chunk($todo, (int) $this->cfg['artist_batch']) as $batch) {
            if ($this->outOfTime()) {
                break;
            }
            if (isset($paused[$batch[0]])) {
                $count['paused']++;
                continue;
            }
            try {
                $found = $this->mb->artistsByIds($batch);
                $count['requests']++;
                $this->store->clearFailure('artists', $batch[0]);
            } catch (MusicBrainzException $e) {
                $count['errors']++;
                $this->store->fail('artists', $batch[0], $e->getMessage(), $this->now);
                continue;
            }
            $this->store->transaction(function () use ($batch, $found, &$count): void {
                foreach ($batch as $mbid) {
                    if (isset($found[$mbid])) {
                        $this->store->saveArtistMusicBrainz($mbid, $found[$mbid], $this->now);
                        $count['found']++;
                    }
                }
            });
            // Not in the search index (e.g. just added): look up one by one.
            foreach (array_diff($batch, array_keys($found)) as $mbid) {
                if ($this->outOfTime()) {
                    break;
                }
                try {
                    $this->store->saveArtistMusicBrainz($mbid, $this->mb->lookupArtist($mbid), $this->now);
                    $count['single_lookups']++;
                } catch (MusicBrainzException) {
                    $this->store->saveArtistMusicBrainz($mbid, null, $this->now);
                    $count['not_found']++;
                }
            }
            $this->dataChanged();
            ($this->log)("artists: {$count['found']} + {$count['single_lookups']} of {$count['needed']}");
        }
        return $count;
    }

    // --- 5. prescore from MusicBrainz data only -------------------------------------------

    private function stagePrescore(string $from, string $to): array
    {
        // Only when something it depends on changed since the last prescore.
        $changed = $this->store->meta('data_changed_at');
        $last = $this->store->meta('prescore_at');
        $window = "$from..$to";
        if ($last !== null && $changed !== null && $changed <= $last && $this->store->meta('prescore_window') === $window) {
            return ['skipped_unchanged' => $last];
        }
        $rows = $this->store->releasesInWindow($from, $to);
        $artists = $this->store->artistsByMbid(array_column($rows, 'artist_mbid'));
        $credits = $this->store->credits(array_column($rows, 'id'));
        $quality = ['good' => 0, 'partial' => 0, 'insufficient' => 0];
        $this->store->transaction(function () use ($rows, $artists, $credits, &$quality): void {
            foreach ($rows as $row) {
                $release = $this->build($row, $artists[$row['artist_mbid']] ?? null, false, $credits[$row['id']] ?? null);
                $a = $this->engine->assess($release);
                $quality[$release['metadata_quality']]++;
                $this->store->setPrescore($row['id'], (float) $a['fit'], $release['metadata_quality']);
            }
        });
        $this->store->setMeta('prescore_at', $this->now);
        $this->store->setMeta('prescore_window', $window);
        return ['releases' => count($rows), 'metadata_from_musicbrainz' => $quality];
    }

    // --- 6. Last.fm where it matters -------------------------------------------------------

    private function stageLastfm(string $from, string $to): array
    {
        $rows = $this->store->releasesInWindow($from, $to);
        $min = (float) $this->cfg['lastfm_min_prescore'];
        $minInterest = (float) ($this->cfg['lastfm_min_interest'] ?? INF);
        $stale = time() - $this->cfg['lastfm_refresh_days'] * 86400;
        // Artist interest per release: only artists you have clearly listened
        // to - not every artist in your library.
        $credits = $this->store->credits(array_column($rows, 'id'));
        $interest = [];
        foreach ($rows as $r) {
            $interest[$r['id']] = isset($credits[$r['id']]) ? $this->interest->forRelease($credits[$r['id']])['value'] : 0.0;
        }

        $why = static fn (array $r): ?string => match (true) {
            $r['watch_level'] !== null => 'known artist',
            $interest[$r['id']] >= $minInterest => 'listened artist',
            (float) $r['prescore'] >= $min => 'looks relevant',
            $r['prescore_quality'] === 'insufficient' => 'no genre data yet',
            default => null,
        };
        $selected = array_values(array_filter($rows, static fn ($r) => $why($r) !== null));
        // Known artists first, then listened artists, then most relevant, then the unknowns.
        $order = static fn (array $r): array => [$r['watch_level'] === null, $interest[$r['id']] < $minInterest, -(float) $r['prescore']];
        usort($selected, static fn ($a, $b) => $order($a) <=> $order($b));

        $count = ['selected' => count($selected), 'reasons' => array_count_values(array_map($why, $selected)),
            'artist_calls' => 0, 'album_calls' => 0, 'already_done' => 0, 'errors' => 0, 'paused' => 0, 'remaining' => 0];
        $paused = $this->paused('lastfm');
        foreach ($selected as $i => $r) {
            if ($this->outOfTime()) {
                $count['remaining'] = count($selected) - $i;
                break;
            }
            if (isset($paused[$r['id']])) {
                $count['paused']++;
                continue;
            }
            $artist = $this->store->artist($r['artist_mbid']);
            $artistDone = $artist !== null && $artist['lastfm_fetched_at'] !== null && strtotime($artist['lastfm_fetched_at']) > $stale;
            $albumDone = $r['lastfm_album_status'] !== null;
            if ($artistDone && $albumDone) {
                $count['already_done']++;
                continue;
            }
            try {
                if (!$artistDone) {
                    $info = $this->lastfm->artistInfo($r['primary_name']);
                    if ($info !== null) {
                        $info['tags'] = array_column($this->lastfm->artistTopTags($r['primary_name'])['tags'], 'name');
                    }
                    $count['artist_calls']++;
                    $this->store->saveArtistLastfm($r['artist_mbid'], $r['primary_name'], $info, $info === null ? 'not_found' : 'found', $this->now);
                    $artist = $this->store->artist($r['artist_mbid']);
                }
                if (!$albumDone) {
                    if ($artist['lastfm_status'] === 'found') {
                        $album = $this->lastfm->albumInfo($r['primary_name'], $r['title']);
                        $count['album_calls']++;
                        $this->store->setLastfmAlbum($r['id'], $album, $album === null ? 'not_found' : 'found', $this->now);
                    } else {
                        $this->store->setLastfmAlbum($r['id'], null, 'skipped', $this->now);
                    }
                }
                $this->store->clearFailure('lastfm', $r['id']);
            } catch (LastFmException $e) {
                $count['errors']++;
                $this->store->fail('lastfm', $r['id'], $e->getMessage(), $this->now);
            }
            if (($i + 1) % 100 === 0) {
                ($this->log)(sprintf('lastfm: %d of %d', $i + 1, count($selected)));
            }
        }
        if ($count['artist_calls'] + $count['album_calls'] > 0) {
            $this->store->setMeta('enrichment_changed_at', $this->now);
            $this->store->setMeta('data_version', (string) ((int) $this->store->meta('data_version') + 1));
        }
        return $count;
    }

    // --- 7. Last.fm Similar Artists -----------------------------------------------------

    /**
     * Last.fm Similar Artists for your profile artists (their whole lists) and
     * for the artists of the window's releases (only links to artists in your
     * Last.fm library are kept - the ones that can matter). Each artist once,
     * refreshed after similar_refresh_days; at most similar_per_day artists a
     * day, so the first collection spreads over several nights and the feed
     * is built meanwhile with what is there (missing data is no penalty).
     * Identity: by MusicBrainz id; else by exact name - confirmed when
     * MusicBrainz' documented link points to that Last.fm page (if it points
     * to another page, that page is used). Last.fm unreachable (several errors
     * in a row): the stage pauses for similar_backoff_hours, the import goes on.
     */
    private function stageSimilar(string $from, string $to): array
    {
        $count = ['due' => 0, 'fetched' => 0, 'calls' => 0, 'confirmed' => 0, 'errors' => 0, 'paused' => 0, 'remaining' => 0];
        if (($until = $this->store->meta('similar_backoff_until')) !== null && $until > $this->now) {
            return $count + ['skipped_backoff' => $until];
        }
        $day = substr((string) $this->now, 0, 10);
        $done = $this->store->meta('similar_day') === $day ? (int) $this->store->meta('similar_done') : 0;
        $left = max(0, (int) $this->cfg['similar_per_day'] - $done);
        $stale = date(DATE_ATOM, time() - (int) $this->cfg['similar_refresh_days'] * 86400);

        // Your profile artists first, then the window's artists - releases with
        // genre data and the newest first.
        $todo = [];
        foreach ($this->profileArtists() as $a) {
            $todo[SimilarArtists::key($a['mbid'], $a['name'])] = $a + ['role' => 'profile'];
        }
        $rows = $this->store->releasesInWindow($from, $to);
        usort($rows, static fn ($a, $b) => [$b['prescore_quality'] !== 'insufficient', $b['first_release_date']] <=> [$a['prescore_quality'] !== 'insufficient', $a['first_release_date']]);
        foreach ($rows as $r) {
            $todo[SimilarArtists::key($r['artist_mbid'], (string) $r['primary_name'])] ??= ['name' => (string) $r['primary_name'], 'mbid' => $r['artist_mbid'] ?: null, 'role' => 'release'];
        }
        $stored = $this->store->similar(array_keys($todo));
        $paused = $this->paused('similar');
        $due = array_filter($todo, static fn ($a, $k) => !isset($paused[$k])
            && (!isset($stored[$k]) || $stored[$k]['fetched_at'] < $stale || ($a['role'] === 'profile' && $stored[$k]['role'] !== 'profile')), ARRAY_FILTER_USE_BOTH);
        $count['due'] = count($due);
        $count['paused'] = count(array_intersect_key($todo, $paused));

        $library = null;
        $inRow = 0;
        foreach ($due as $key => $a) {
            if ($this->outOfTime() || $left === 0) {
                break;
            }
            try {
                [$page, $identity, $list] = $this->fetchSimilar($a, $count);
                if ($a['role'] !== 'profile') {
                    $library ??= $this->libraryIndex();
                    $list = array_values(array_filter($list, static fn ($s) => ($s['mbid'] !== '' && isset($library['m:' . $s['mbid']]))
                        || isset($library['n:' . ListeningLibrary::key($s['name'])])));
                }
                $links = array_map(static fn ($s) => [$s['name'], $s['mbid'], $s['match']], $list);
                $this->store->saveSimilar($key, $a['name'], $a['mbid'], $page, $identity, $a['role'], $links, count($list), (string) $this->now);
                $this->store->clearFailure('similar', $key);
                $count['fetched']++;
                $left--;
                $done++;
                $inRow = 0;
            } catch (LastFmException $e) {
                $count['errors']++;
                $this->store->fail('similar', $key, $e->getMessage(), (string) $this->now);
                if (++$inRow >= 3) {
                    // Last.fm seems unreachable: pause this stage, the import goes on.
                    $this->store->setMeta('similar_backoff_until', date(DATE_ATOM, time() + (int) $this->cfg['similar_backoff_hours'] * 3600));
                    $count['errors'] -= $inRow;
                    $count['backoff'] = true;
                    break;
                }
            }
        }
        $this->store->setMeta('similar_day', $day);
        $this->store->setMeta('similar_done', (string) $done);
        $count['remaining'] = $count['due'] - $count['fetched'];
        if ($count['fetched'] > 0) {
            $this->dataChanged();
        }
        return $count;
    }

    /**
     * One artist's list: by MusicBrainz id; else by exact name, checked against
     * MusicBrainz' link to Last.fm.
     *
     * @return array{0: ?string, 1: string, 2: list<array{name: string, mbid: string, match: float}>} page, identity, list
     */
    private function fetchSimilar(array $a, array &$count): array
    {
        $limit = (int) $this->cfg['similar_limit'];
        $res = null;
        $identity = 'none';
        if ($a['mbid']) {
            $res = $this->lastfm->artistSimilar($a['mbid'], $a['name'], $limit);
            $count['calls']++;
            $identity = $res !== null ? 'mbid' : 'none';
        }
        if ($res === null) {
            $res = $this->lastfm->artistSimilar(null, $a['name'], $limit);
            $count['calls']++;
            if ($res !== null) {
                $identity = ListeningLibrary::key($res['artist']) === ListeningLibrary::key($a['name']) ? 'name' : 'corrected';
            }
            if ($a['mbid']) {
                try {
                    $mbPage = $this->mb->artistLastfmPage($a['mbid']);
                } catch (MusicBrainzException) {
                    $mbPage = null; // no confirmation now - the name match stays uncertain
                }
                if ($mbPage !== null) {
                    if ($res !== null && ListeningLibrary::key($mbPage) === ListeningLibrary::key($res['artist'])) {
                        $identity = 'confirmed';
                    } elseif (($other = $this->lastfm->artistSimilar(null, $mbPage, $limit)) !== null) {
                        $count['calls']++;
                        [$res, $identity] = [$other, 'confirmed'];
                    }
                    $count['confirmed'] += $identity === 'confirmed' ? 1 : 0;
                }
            }
        }
        return [$res['artist'] ?? null, $identity, $res['similar'] ?? []];
    }

    /** @return array<string, true> m:<mbid> and n:<name key> of every artist in your Last.fm library */
    private function libraryIndex(): array
    {
        $idx = [];
        foreach ($this->store->listening() as $l) {
            $idx['n:' . $l['name_key']] = true;
            foreach (['mbid', 'lastfm_mbid'] as $f) {
                if ($l[$f] !== '') {
                    $idx['m:' . $l[$f]] = true;
                }
            }
        }
        foreach ($this->profileArtists() as $a) {
            $idx['n:' . ListeningLibrary::key($a['name'])] = true;
            if ($a['mbid']) {
                $idx['m:' . $a['mbid']] = true;
            }
        }
        return $idx;
    }

    /** @return list<array{name: string, mbid: ?string}> the artists in your music profile */
    private function profileArtists(): array
    {
        return array_map(static fn ($a) => ['name' => (string) $a['name'], 'mbid' => ($a['musicbrainz']['mbid'] ?? null) ?: null], $this->profile['artists'] ?? []);
    }

    // --- 8. final feed -------------------------------------------------------------------

    private function stageFinal(string $from, string $to): array
    {
        $rows = $this->store->releasesInWindow($from, $to);
        $artists = $this->store->artistsByMbid(array_column($rows, 'artist_mbid'));
        $credits = $this->store->credits(array_column($rows, 'id'));

        // One entry per artist + title (an album and its EP twin, or two
        // MusicBrainz entries for the same record, would show up twice).
        $releases = [];
        $seen = [];
        $duplicates = [];
        foreach ($rows as $row) {
            $key = $row['artist_mbid'] . '|' . mb_strtolower(trim((string) preg_replace('/\s*[\(\[].*$/', '', $row['title'])));
            if (isset($seen[$key])) {
                $duplicates[] = ['kept' => $seen[$key], 'dropped' => $row['id'], 'title' => $row['title']];
                continue;
            }
            $seen[$key] = $row['id'];
            $releases[$row['id']] = $this->build($row, $artists[$row['artist_mbid']] ?? null, true, $credits[$row['id']] ?? null);
        }
        // Last.fm Similar Artists links between each release's artist and your artists.
        $profileArtists = $this->profileArtists();
        $keys = array_map(static fn ($a) => SimilarArtists::key($a['mbid'], $a['name']), $profileArtists);
        foreach ($releases as $r) {
            $keys[] = SimilarArtists::key($r['artist']['mbid'] ?: null, (string) $r['artist']['primary_name']);
        }
        $similar = new SimilarArtists($this->store->similar($keys));
        $linked = 0;
        foreach ($releases as $id => $r) {
            $releases[$id]['similar'] = $similar->links(['mbid' => $r['artist']['mbid'] ?: null, 'name' => (string) $r['artist']['primary_name']], $profileArtists);
            $linked += $releases[$id]['similar']['links'] !== [] ? 1 : 0;
        }
        $result = $this->engine->run($releases);

        $byQuality = array_count_values(array_column($releases, 'metadata_quality'));
        $pre = array_count_values(array_filter(array_column($rows, 'prescore_quality')));
        $origin = array_count_values(array_column($rows, 'origin'));
        return $result + [
            'releases' => $releases,
            'duplicates' => $duplicates,
            'stats' => [
                'releases_in_window' => count($rows),
                'by_origin' => $origin,
                'duplicates_removed' => count($duplicates),
                'metadata_after_musicbrainz' => ['good' => $pre['good'] ?? 0, 'partial' => $pre['partial'] ?? 0, 'insufficient' => $pre['insufficient'] ?? 0],
                'metadata_final' => ['good' => $byQuality['good'] ?? 0, 'partial' => $byQuality['partial'] ?? 0, 'insufficient' => $byQuality['insufficient'] ?? 0],
                'decisions' => array_count_values(array_column($result['assessments'], 'decision')),
                'recommended_by_origin' => array_count_values(array_column($result['recommended'], 'origin')),
                'interest_levels' => array_count_values(array_map(static fn ($r) => $r['interest']['level'], $releases)),
                'recommended_by_interest' => array_count_values(array_column($result['recommended'], 'interest_level')),
                'interest_assisted' => count(array_filter($result['recommended'], static fn ($p) => $p['interest_assisted'] ?? false)),
                'recommended_limited_data' => count(array_filter($result['recommended'], static fn ($p) => $p['data_quality'] === 'limited')),
                'wildcards' => count($result['wildcards']),
                'lastfm_linked' => $linked,
            ],
        ];
    }

    /**
     * Stored row (+ artist row, credited artists) -> release with documented
     * facts and artist interest, as the engine expects.
     *
     * @param list<array<string, mixed>>|null $credits  null: not stored yet (the first artist stands in)
     */
    public function build(array $row, ?array $artist, bool $withLastfm, ?array $credits = null): array
    {
        $release = [
            'id' => $row['id'],
            'title' => $row['title'],
            'type' => $row['type'],
            'first_release_date' => $row['first_release_date'],
            'artist' => ['name' => $row['artist_name'], 'mbid' => $row['artist_mbid'], 'primary_name' => $row['primary_name'], 'credited_artists' => (int) $row['credited'],
                'credits' => $credits ?? [['position' => 0, 'mbid' => $row['artist_mbid'], 'name' => $row['artist_name'], 'artist_name' => $row['primary_name'], 'joinphrase' => '']]],
            'release_tags' => json_decode((string) $row['release_tags'], true) ?: [],
            'source' => $row['origin'] === 'pool' ? 'pool' : 'known_artist:' . ($row['watch_level'] ?? 'interested'),
            'date_check' => $row['date_check'] ? json_decode($row['date_check'], true) : ['status' => strlen($row['first_release_date']) === 10 ? 'confirmed' : 'unconfirmed', 'date' => $row['first_release_date'], 'note' => null, 'release_dates' => []],
            'musicbrainz_url' => "https://musicbrainz.org/release-group/{$row['id']}",
        ];
        // Every credited artist counts; the profile decides (a stored level only
        // stands in for releases whose credits are not stored yet).
        $release['interest'] = $this->interest->forRelease($release['artist']['credits']);
        $watch = $release['interest']['watch'];
        $level = $credits !== null ? ($watch['level'] ?? null) : ($watch['level'] ?? $row['watch_level']);
        if ($level !== null) {
            $release['watch_level'] = $level;
            $release['watch_artist'] = $watch ?? ['mbid' => $row['artist_mbid'], 'name' => $row['primary_name'], 'level' => $level];
        }
        $mb = $artist && $artist['mb'] ? json_decode($artist['mb'], true) : null;
        $lfArtist = $withLastfm && $artist && $artist['lastfm_status'] === 'found' ? json_decode($artist['lastfm'], true) : null;
        $lfAlbum = $withLastfm && $row['lastfm_album_status'] === 'found' ? json_decode($row['lastfm_album'], true) : null;
        $checked = $withLastfm && $artist !== null && $artist['lastfm_fetched_at'] !== null;
        return $release + FactBuilder::build($release, $mb, $lfArtist, $lfAlbum, $checked);
    }

    /** Items that failed max_attempts times recently: skipped until the pause is over. */
    private function paused(string $stage): array
    {
        $since = date(DATE_ATOM, time() - (int) $this->cfg['failure_pause_hours'] * 3600);
        return $this->store->pausedFailures($stage, (int) $this->cfg['max_attempts'], $since);
    }

    private function outOfTime(): bool
    {
        return $this->deadline > 0 && microtime(true) >= $this->deadline;
    }
}
