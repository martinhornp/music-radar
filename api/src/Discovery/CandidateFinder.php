<?php

declare(strict_types=1);

namespace MusicRadar\Discovery;

use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\MusicBrainz\MusicBrainzException;

/**
 * Finds real, new albums and EPs on MusicBrainz - without using any
 * service's recommendations ("similar artists").
 *
 * Pool: release groups whose FIRST release date is an exact day in the
 * window, primary type Album or EP, official, and no secondary type (no live,
 * compilation, remix, DJ-mix, demo, soundtrack...) - except Mixtape/Street on
 * its own: mixtapes are usually full new albums (hip hop, electronic).
 *
 * Expanded editions (readEditions): a new release of an older album or EP
 * with more tracks than every earlier release of it (a deluxe with new songs)
 * counts as new music on its own date. Remasters and plain reissues do not. Reissues and remasters
 * belong to an older release group with an earlier first date, so they fall
 * out.
 *
 * Two MusicBrainz search quirks shape how the pool is built:
 *  - a search returns at most its first 500 hits, and
 *  - release groups with only a year or month ("2026", "2026-10") match EVERY
 *    day of a date query - ~85 % of a typical day's hits.
 * So each day is queried separately per type (album / EP), every reachable
 * page is read, and only exact-dated release groups are kept. The share of a
 * day that could not be reached is estimated and reported.
 *
 * Sources, recorded on every candidate so bias can be measured:
 *   random        seeded uniform sample of that exact-dated pool
 *   tag:x         the window filtered by a MusicBrainz tag from the
 *                 listener's profile (+ neighbouring genres)
 *   random_topup  more random picks if the tags run dry
 * At most one release per artist; "Various Artists" is skipped.
 */
final class CandidateFinder
{
    public const VARIOUS_ARTISTS = '89ad4ac3-39f7-470e-963a-56509c546377';
    private const MAX_HITS = 500; // MusicBrainz search limit
    /** An edition with one of these in its title is a remaster/reissue, not new music. */
    private const REISSUE_EDITION = '/\b(remaster(ed)?|reissue|re-issue|anniversary|live at|live in|remix(es)?)\b/i';
    private const SUSPICIOUS_TITLE = '/\b(remaster(ed)?|reissue|re-issue|deluxe|anniversary|live at|live in|remix(es)?|greatest hits|best of|a decade of|anthology|retrospective|collection|essentials|the very best)\b/i';

    /** @var callable(string): void */
    private $log;
    private string $from = '';
    private string $to = '';

    public function __construct(private readonly MusicBrainzClient $mb, ?callable $log = null)
    {
        $this->log = $log ?? static function (string $m): void {
        };
    }

    public static function baseQuery(string $from, string $to, string $types = '(primarytype:album OR primarytype:ep)'): string
    {
        return "firstreleasedate:[$from TO $to] AND $types AND status:official"
            . ' AND -(secondarytype:* AND -secondarytype:"mixtape/street")';
    }

    /** No secondary type, or only Mixtape/Street (see the class comment). */
    public static function newMusicType(array $rg): bool
    {
        $secondary = $rg['secondary-types'] ?? [];
        return $secondary === [] || $secondary === ['Mixtape/Street'];
    }

    /**
     * @param list<string> $tags  MusicBrainz tags to search (from the profile)
     * @return array{candidates: list<array<string, mixed>>, stats: array<string, mixed>}
     */
    public function find(string $from, string $to, int $target, float $randomShare, array $tags, int $seed): array
    {
        $this->from = $from;
        $this->to = $to;
        $errors = [];
        $base = self::baseQuery($from, $to);
        $windowHits = $this->mb->searchReleaseGroups($base, 1)['count'];

        [$pool, $perDay] = $this->exactPool($from, $to, $errors);
        $estimatedTotal = (int) round(array_sum(array_column($perDay, 'estimated_exact')));
        ($this->log)(sprintf('Exact-dated pool: %d found (estimated %d in total, %d%% reachable)',
            count($pool), $estimatedTotal, $estimatedTotal ? round(count($pool) / $estimatedTotal * 100) : 0));

        $seen = ['rg' => [], 'artist' => []];
        $excluded = [];
        $picked = [];

        // 1) Random sample of the exact-dated pool.
        $poolCount = count($pool);
        mt_srand($seed);
        $this->shuffle($pool);
        $randomTarget = (int) round($target * $randomShare);
        $randomPicked = 0;
        while ($randomPicked < $randomTarget && $pool !== []) {
            if ($c = $this->accept(array_shift($pool), 'random', $seen, $excluded)) {
                $picked[] = $c;
                $randomPicked++;
            }
        }

        // 2) Genre-tag searches, round-robin over the tags.
        $tagTarget = $target - $randomPicked;
        $perTag = [];
        foreach ($tagTarget > 0 ? $tags : [] as $tag) {
            try {
                $res = $this->mb->searchReleaseGroups($base . ' AND tag:"' . str_replace('"', '', $tag) . '"', 100);
            } catch (MusicBrainzException $e) {
                $errors[] = "tag $tag: " . $e->getMessage();
                continue;
            }
            $exact = array_values(array_filter($res['release-groups'], $this->isExactInWindow(...)));
            $perTag[$tag] = ['hits' => $res['count'], 'exact_dated' => count($exact), 'rows' => $exact, 'picked' => 0];
        }
        $tagPicked = 0;
        for ($round = 0; $tagPicked < $tagTarget && $round < 50; $round++) {
            $progress = false;
            foreach ($perTag as $tag => &$t) {
                while ($tagPicked < $tagTarget && $t['rows'] !== []) {
                    if ($c = $this->accept(array_shift($t['rows']), 'tag:' . $tag, $seen, $excluded)) {
                        $picked[] = $c;
                        $t['picked']++;
                        $tagPicked++;
                        $progress = true;
                        break;
                    }
                }
            }
            unset($t);
            if (!$progress) {
                break;
            }
        }

        // 3) Top up from the random pool if the tags ran dry (documented).
        $topUp = 0;
        while (count($picked) < $target && $pool !== []) {
            if ($c = $this->accept(array_shift($pool), 'random_topup', $seen, $excluded)) {
                $picked[] = $c;
                $topUp++;
            }
        }

        return [
            'candidates' => $picked,
            'stats' => [
                'window' => [$from, $to],
                'query' => $base,
                'seed' => $seed,
                'window_hits_incl_partial_dates' => $windowHits,
                'exact_dated_found' => $poolCount,
                'exact_dated_estimated_total' => $estimatedTotal,
                'per_day' => $perDay,
                'pool_size' => $estimatedTotal,
                'target' => $target,
                'found' => count($picked),
                'random' => $randomPicked,
                'tag' => $tagPicked,
                'random_topup' => $topUp,
                'errors' => $errors,
                'tags' => array_map(static fn ($t) => ['hits' => $t['hits'], 'exact_dated' => $t['exact_dated'], 'picked' => $t['picked']], $perTag),
                'excluded' => array_count_values(array_column($excluded, 'reason')),
                'excluded_examples' => array_slice($excluded, 0, 15),
            ],
        ];
    }

    /**
     * Every reachable exact-dated release group per day and type.
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function exactPool(string $from, string $to, array &$errors): array
    {
        $pool = [];
        $perDay = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $day = ['hits' => 0, 'read' => 0, 'exact_found' => 0, 'estimated_exact' => 0.0, 'partitions' => 0, 'over_limit' => 0];
            foreach (['album', 'ep'] as $type) {
                $slice = $this->readSlice($d, $type, null, $errors);
                $pool = [...$pool, ...$slice['release_groups']];
                foreach (['hits', 'read', 'partitions', 'over_limit'] as $k) {
                    $day[$k] += $slice[$k];
                }
                $day['exact_found'] += count($slice['release_groups']);
            }
            // If some partitions were cut off at 500, estimate what was missed.
            $day['estimated_exact'] = round($day['read'] ? $day['exact_found'] * ($day['read'] + $day['over_limit']) / $day['read'] : 0, 1);
            $perDay[$d] = $day;
            ($this->log)(sprintf('%s: %d exact-dated (of %d hits, %d partitions%s)', $d, $day['exact_found'], $day['hits'], $day['partitions'], $day['over_limit'] ? ", {$day['over_limit']} unreachable" : ''));
        }
        return [$pool, $perDay];
    }

    /**
     * Every exact-dated album or EP first released on one day. Reads all
     * pages, splitting by artist-name range while a query has > 500 hits.
     *
     * @param int|null $maxAgeSeconds  how old a cached MusicBrainz answer may be
     * @return array{release_groups: list<array<string, mixed>>, hits: int, read: int, partitions: int, over_limit: int}
     */
    /**
     * @param (callable(): bool)|null $stop  checked before every request; when it
     *        returns true the slice is left unfinished ('interrupted' => true)
     */
    public function readSlice(string $day, string $type, ?int $maxAgeSeconds, array &$errors, ?callable $stop = null): array
    {
        $out = ['release_groups' => [], 'hits' => 0, 'read' => 0, 'partitions' => 0, 'over_limit' => 0, 'interrupted' => false];
        $seen = [];
        $queue = [['*', '*']];
        $first = true;
        while ($queue !== []) {
            if ($stop !== null && $stop()) {
                $out['interrupted'] = true;
                return $out;
            }
            $range = array_shift($queue);
            $q = self::baseQuery($day, $day, "primarytype:$type") . self::nameClause($range);
            try {
                $page = $this->mb->searchReleaseGroups($q, 100, 0, $maxAgeSeconds);
            } catch (MusicBrainzException $e) {
                $errors[] = "$day $type " . implode('-', $range) . ': ' . $e->getMessage();
                continue;
            }
            $hits = $page['count'];
            if ($first) {
                $out['hits'] = $hits; // the unsplit query: total for this day/type
                $first = false;
            }
            if ($hits > self::MAX_HITS && ($split = self::splitRange($range)) !== []) {
                array_push($queue, ...$split);
                continue;
            }
            $out['partitions']++;
            $rows = $page['release-groups'];
            for ($off = 100; $off < min($hits, self::MAX_HITS); $off += 100) {
                if ($stop !== null && $stop()) {
                    $out['interrupted'] = true;
                    return $out;
                }
                try {
                    $rows = [...$rows, ...$this->mb->searchReleaseGroups($q, 100, $off, $maxAgeSeconds)['release-groups']];
                } catch (MusicBrainzException $e) {
                    $errors[] = "$day $type @$off: " . $e->getMessage();
                }
            }
            if ($hits > self::MAX_HITS) {
                $out['over_limit'] += $hits - self::MAX_HITS; // could not split further
            }
            foreach ($rows as $rg) {
                $out['read']++;
                if (($rg['first-release-date'] ?? '') === $day && !isset($seen[$rg['id']])) {
                    $seen[$rg['id']] = true;
                    $out['release_groups'][] = $rg;
                }
            }
        }
        return $out;
    }

    /**
     * Expanded editions released on one day (see the class comment), as
     * release-group records dated that day with the edition's title and credit.
     * Only releases titled differently from their album are looked at - a
     * deluxe gets its own title, a plain reissue keeps it - which keeps this to
     * the day's release pages plus one request per 20 such albums.
     *
     * @param array<string, true> $known  release groups first released on $day
     * @param (callable(): bool)|null $stop  as readSlice(); null is returned when it stops
     * @return list<array<string, mixed>>|null
     */
    public function readEditions(string $day, string $type, array $known, ?int $maxAgeSeconds, array &$errors, ?callable $stop = null): ?array
    {
        // Digital only: an expanded edition is always released digitally, and the
        // CD/vinyl copies of the same albums would roughly double the pages.
        $base = "date:$day AND primarytype:$type AND status:official AND format:\"Digital Media\""
            . ' AND -(secondarytype:* AND -secondarytype:"mixtape/street")';
        $editions = [];
        $queue = [['*', '*']];
        while ($queue !== []) {
            if ($stop !== null && $stop()) {
                return null;
            }
            $range = array_shift($queue);
            $q = $base . self::nameClause($range);
            try {
                $page = $this->mb->searchReleases($q, 100, 0, $maxAgeSeconds);
            } catch (MusicBrainzException $e) {
                $errors[] = "$day $type editions " . implode('-', $range) . ': ' . $e->getMessage();
                continue;
            }
            $hits = $page['count'];
            if ($hits > self::MAX_HITS && ($split = self::splitRange($range)) !== []) {
                array_push($queue, ...$split);
                continue;
            }
            $rows = $page['releases'];
            for ($off = 100; $off < min($hits, self::MAX_HITS); $off += 100) {
                if ($stop !== null && $stop()) {
                    return null;
                }
                try {
                    $rows = [...$rows, ...$this->mb->searchReleases($q, 100, $off, $maxAgeSeconds)['releases']];
                } catch (MusicBrainzException $e) {
                    $errors[] = "$day $type editions @$off: " . $e->getMessage();
                }
            }
            foreach ($rows as $r) {
                $rg = $r['release-group'] ?? [];
                $id = (string) ($rg['id'] ?? '');
                $title = (string) ($r['title'] ?? '');
                $tracks = (int) ($r['track-count'] ?? 0);
                if ($id === '' || isset($known[$id]) || ($r['date'] ?? '') !== $day || $tracks === 0
                    || $title === (string) ($rg['title'] ?? '') || preg_match(self::REISSUE_EDITION, $title)) {
                    continue;
                }
                if (!isset($editions[$id]) || $tracks > $editions[$id]['tracks']) {
                    $editions[$id] = ['tracks' => $tracks, 'earlier' => 0, 'rg' => [
                        'id' => $id, 'title' => $title, 'primary-type' => (string) ($rg['primary-type'] ?? ''),
                        'first-release-date' => $day, 'secondary-types' => $rg['secondary-types'] ?? [],
                        'artist-credit' => $r['artist-credit'] ?? [], 'tags' => [],
                    ]];
                }
            }
        }
        // Keep an edition only if its album was released before and never with as many tracks.
        $before = date('Y-m-d', strtotime("$day -1 day"));
        foreach (array_chunk(array_keys($editions), 20) as $ids) {
            $q = "date:[0000 TO $before] AND rgid:(" . implode(' OR ', $ids) . ')';
            for ($off = 0, $hits = 1; $off < min($hits, self::MAX_HITS); $off += 100) {
                if ($stop !== null && $stop()) {
                    return null;
                }
                try {
                    $page = $this->mb->searchReleases($q, 100, $off, $maxAgeSeconds);
                } catch (MusicBrainzException $e) {
                    $errors[] = "$day $type editions check: " . $e->getMessage();
                    break;
                }
                $hits = $page['count'];
                foreach ($page['releases'] as $r) {
                    $id = (string) ($r['release-group']['id'] ?? '');
                    if (isset($editions[$id])) {
                        $editions[$id]['earlier'] = max($editions[$id]['earlier'], 1);
                        if ((int) ($r['track-count'] ?? 0) >= $editions[$id]['tracks']) {
                            $editions[$id]['earlier'] = PHP_INT_MAX;
                        }
                    }
                }
            }
        }
        return array_values(array_map(static fn ($e) => $e['rg'],
            array_filter($editions, static fn ($e) => $e['earlier'] === 1)));
    }

    /**
     * A MusicBrainz release-group record as our release structure, or null for
     * things that are never candidates (various artists, no artist, wrong type).
     */
    public static function toRelease(array $rg): ?array
    {
        $credits = $rg['artist-credit'] ?? [];
        $artistIds = array_map(static fn ($c) => (string) ($c['artist']['id'] ?? ''), $credits);
        if ($credits === [] || in_array(self::VARIOUS_ARTISTS, $artistIds, true)
            || !self::newMusicType($rg) || !in_array($rg['primary-type'] ?? '', ['Album', 'EP'], true)) {
            return null;
        }
        return [
            'id' => (string) $rg['id'],
            'title' => (string) ($rg['title'] ?? ''),
            'type' => (string) $rg['primary-type'],
            'first_release_date' => (string) ($rg['first-release-date'] ?? ''),
            'artist' => self::artist($credits),
            'release_tags' => array_values(array_map(static fn ($t) => (string) $t['name'], $rg['tags'] ?? [])),
        ];
    }

    /**
     * The release's artist from its MusicBrainz artist credit - the same
     * structure whichever stage found the release, so a release never changes
     * artist between stages:
     *   name             as credited, joined ("Anderson .Paak & Cordae")
     *   mbid / primary_name  the first credited artist (genre data, Last.fm lookups)
     *   credits          every credited artist in order: MusicBrainz id, name as
     *                    credited, the artist's own name and the join phrase after it
     *
     * @param list<array<string, mixed>> $credits  MusicBrainz 'artist-credit'
     */
    public static function artist(array $credits): array
    {
        $name = '';
        $list = [];
        foreach (array_values($credits) as $i => $c) {
            $name .= ($c['name'] ?? '') . ($c['joinphrase'] ?? '');
            $list[] = [
                'position' => $i,
                'mbid' => (string) ($c['artist']['id'] ?? ''),
                'name' => (string) ($c['name'] ?? $c['artist']['name'] ?? ''),
                'artist_name' => (string) ($c['artist']['name'] ?? $c['name'] ?? ''),
                'joinphrase' => (string) ($c['joinphrase'] ?? ''),
            ];
        }
        return [
            'name' => trim($name),
            'mbid' => $list[0]['mbid'] ?? '',
            'primary_name' => $list[0]['artist_name'] ?? '',
            'credited_artists' => count($list),
            'credits' => $list,
        ];
    }

    /** Whether a release group should be skipped as a reissue/live/compilation by its title. */
    public static function looksLikeReissue(string $title): bool
    {
        return (bool) preg_match(self::SUSPICIOUS_TITLE, $title);
    }

    /**
     * Splits an artist-name range ['*'|letter, '*'|letter] in two at the
     * middle letter. Ranges overlap only on their boundary token, so together
     * they still cover every name (incl. non-Latin, which sort after 'z').
     *
     * @param array{0: string, 1: string} $range
     * @return list<array{0: string, 1: string}>  [] if it can't be split
     */
    public static function splitRange(array $range): array
    {
        $alphabet = range('a', 'z');
        $lo = $range[0] === '*' ? -1 : (int) array_search($range[0], $alphabet, true);
        $hi = $range[1] === '*' ? 26 : (int) array_search($range[1], $alphabet, true);
        if ($hi - $lo < 2) {
            return [];
        }
        $mid = $alphabet[intdiv($lo + $hi, 2)];
        return [[$range[0], $mid], [$mid, $range[1]]];
    }

    private static function nameClause(array $range): string
    {
        return $range === ['*', '*'] ? '' : " AND artistname:[{$range[0]} TO {$range[1]}]";
    }

    private function isExactInWindow(array $rg): bool
    {
        $date = (string) ($rg['first-release-date'] ?? '');
        return strlen($date) === 10 && $date >= $this->from && $date <= $this->to;
    }

    /** @return array<string, mixed>|null */
    private function accept(array $rg, string $source, array &$seen, array &$excluded): ?array
    {
        $id = (string) ($rg['id'] ?? '');
        $credits = $rg['artist-credit'] ?? [];
        $artistIds = array_map(static fn ($c) => (string) ($c['artist']['id'] ?? ''), $credits);
        $title = (string) ($rg['title'] ?? '');
        $reason = match (true) {
            isset($seen['rg'][$id]) => 'duplicate release',
            !$this->isExactInWindow($rg) => 'release date not an exact day in the window',
            $credits === [] => 'no artist',
            in_array(self::VARIOUS_ARTISTS, $artistIds, true) => 'various artists',
            !self::newMusicType($rg) => 'secondary type',
            !in_array($rg['primary-type'] ?? '', ['Album', 'EP'], true) => 'not album/EP',
            (bool) preg_match(self::SUSPICIOUS_TITLE, $title) => 'title suggests reissue/live/remix',
            isset($seen['artist'][$artistIds[0]]) => 'artist already has a candidate',
            default => null,
        };
        if ($reason !== null) {
            if ($reason !== 'duplicate release') {
                $excluded[] = ['reason' => $reason, 'title' => $title, 'artist' => $credits[0]['name'] ?? '?'];
            }
            return null;
        }
        $seen['rg'][$id] = true;
        $seen['artist'][$artistIds[0]] = true;

        return [
            'id' => $id,
            'title' => $title,
            'type' => $rg['primary-type'],
            'first_release_date' => (string) $rg['first-release-date'],
            'artist' => self::artist($credits),
            'release_tags' => array_values(array_map(static fn ($t) => (string) $t['name'], $rg['tags'] ?? [])),
            'source' => $source,
            'musicbrainz_url' => "https://musicbrainz.org/release-group/$id",
        ];
    }

    private function shuffle(array &$a): void
    {
        for ($i = count($a) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$a[$i], $a[$j]] = [$a[$j], $a[$i]];
        }
    }
}
