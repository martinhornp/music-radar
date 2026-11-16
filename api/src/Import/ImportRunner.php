<?php

declare(strict_types=1);

namespace MusicRadar\Import;

use Closure;
use MusicRadar\Discovery\DiscoveryPipeline;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Feed\FeedBuilder;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Feedback\FeedbackSet;
use MusicRadar\Feedback\Schema;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\Recommend\ArtistInterest;
use MusicRadar\Recommend\RecommendationEngine;
use MusicRadar\Recommend\TasteModel;
use PDO;
use Throwable;

/**
 * The daily import on the server, as many short calls (no SSH, PHP time
 * limits): each call does at most $budget seconds of work and stops in a
 * consistent state; the next call continues. When collecting is complete
 * and the feed is due, it builds the recommendations and publishes them as
 * a new run (activated atomically - the site never sees a half feed).
 *
 * All state lives in MariaDB: discovery data, profile, feedback, runs, log.
 * Only one call works at a time (database lock); others get "busy".
 */
final class ImportRunner
{
    private const LOCK = 'music_radar_import';

    /**
     * The code and settings that turn the stored data into recommendations
     * (relative to api/): when any of them changes, a deploy changes the
     * algorithm version and the next call recalculates.
     */
    private const ALGORITHM_FILES = ['src/Recommend', 'src/Taste', 'src/Discovery', 'src/Feed', 'config/recommender.php', 'config/discovery.php',
        'data/tag-classes.php', 'data/musicbrainz-genres.txt', 'data/artist-overrides.php'];

    /**
     * @param Closure(): MusicBrainzClient $mb
     * @param Closure(): LastFmClient $lastfm   may throw if no key is configured
     * @param list<string> $secrets            values to redact from anything logged/returned
     * @param string|null $algorithm            algorithm version (tests); default: computed from ALGORITHM_FILES
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $discoveryConfig,
        private readonly array $engineConfig,
        private readonly Closure $mb,
        private readonly Closure $lastfm,
        private readonly array $secrets = [],
        private readonly ?Closure $clock = null,
        private readonly ?string $algorithm = null,
        private readonly ?Closure $tidal = null,
    ) {
    }

    /**
     * One step of the import.
     *
     * @return array{status: string, done: bool, ...}
     *   status: working (more to do) | done (data current, feed up to date) |
     *           busy (another call is running) | error
     */
    public function step(int $budgetSeconds, ?string $today = null): array
    {
        $t0 = microtime(true);
        $now = $this->now();
        $today ??= substr($now, 0, 10);
        $log = new ImportLog($this->db);

        if (!$this->lock()) {
            return ['status' => 'busy', 'done' => false, 'message' => 'another import call is running'];
        }
        try {
            $store = new DiscoveryStore($this->db);
            $feeds = new RecommendationStore($this->db);
            $feeds->install();
            $log->install();
            Schema::ensure($this->db);

            $profileRow = $store->latestProfile();
            if ($profileRow === null) {
                throw new \RuntimeException('No music profile in the database yet (run the migration or the profile refresh)');
            }
            $profile = $profileRow['profile'];
            $feedbackRepo = new FeedbackRepository($this->db);
            $feedback = FeedbackSet::fromRepository($feedbackRepo);
            $engine = new RecommendationEngine(TasteModel::fromProfile($profile, $this->engineConfig), $this->engineConfig, $feedback->forEngine());
            $pipeline = new DiscoveryPipeline($store, ($this->mb)(), ($this->lastfm)(), $engine, $profile, $this->discoveryConfig,
                interest: $this->interest($store, $profile, $feedback));

            // 1. Collect / enrich what is due, within the budget.
            $run = $pipeline->run($today, max(1, $budgetSeconds - 2), false);
            $stages = $this->compact($run['stats']['stages']);
            $complete = $run['stats']['complete'];

            // A call with new errors is not "done": failed items are retried on
            // the next call until they are paused (max_attempts), then it finishes.
            $errors = array_sum(array_map(static fn ($s) => (int) ($s['errors'] ?? 0), $run['stats']['stages']));
            $complete = $complete && $errors === 0;

            // 2. Recommendations, once collecting is complete and something changed.
            $versions = ['data' => (int) $store->meta('data_version'), 'feedback' => $feedbackRepo->version(), 'profile' => $profileRow['id'],
                'algorithm' => $this->algorithm ?? self::algorithmVersion()];
            $feedInfo = null;
            $due = $complete ? $this->feedDue($feeds->activeRun(), $versions, $today) : null;
            if ($due !== null) {
                if (microtime(true) - $t0 > $budgetSeconds * 0.5) {
                    $feedInfo = ['published' => false, 'reason' => "$due - next call"];
                } else {
                    $final = $pipeline->buildFeed($today);
                    $feed = FeedBuilder::feed($final['recommended'], $final['wildcards'], $final['releases'], $final['assessments'],
                        $profile, $now, $run['stats']['window'], (int) $final['stats']['releases_in_window']);
                    $runId = $feeds->publish($feed, 'import', $final['stats'] + ['reason' => $due, 'feedback' => $feedback->summary(),
                        'versions' => $versions], $now, FeedBuilder::scores($final['assessments']));
                    $kinds = array_count_values(array_column(array_column($feed['releases'], 'pick'), 'kind'));
                    $feedInfo = ['published' => true, 'run_id' => $runId, 'reason' => $due,
                        'recommended' => $kinds['recommended'] ?? 0, 'wildcards' => $kinds['wildcard'] ?? 0,
                        'seconds' => $final['stats']['seconds']];
                }
            }

            $done = $complete && ($due === null || ($feedInfo['published'] ?? false));
            $musicDone = $done; // collecting and the feed are complete; only TIDAL links may be left
            $tidal = null;
            // The core feed is already active. Optional links have their own retry
            // state and time budget; an outage never turns a successful import red.
            if ($done && $this->tidal !== null) {
                try {
                    $tidal = ($this->tidal)($now, max(0, $budgetSeconds - (microtime(true) - $t0) - 0.5));
                    $done = !($tidal['pending'] ?? false);
                } catch (Throwable) {
                    // Infrastructure failure: keep the feed active, but explicitly
                    // defer optional work rather than claim the queue was drained.
                    $tidal = ['pending' => false, 'status' => 'error', 'deferred' => true];
                }
            }
            $result = [
                'status' => $done ? 'done' : 'working',
                'done' => $done,
                'music_done' => $musicDone,
                'today' => $today,
                'stages' => $stages,
                'feed' => $feedInfo,
                'tidal' => $tidal,
                'errors' => $errors,
                'failures' => count($store->failures()),
                'seconds' => round(microtime(true) - $t0, 1),
                'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];
            $log->add($now, microtime(true) - $t0, $result['status'], $result, null);
            return $result;
        } catch (Throwable $e) {
            $message = $this->redact($e::class . ': ' . $e->getMessage());
            error_log("import: $message");
            try {
                $log->add($now, microtime(true) - $t0, 'error', ['today' => $today], $message);
            } catch (Throwable) {
                // logging must never hide the original error
            }
            return ['status' => 'error', 'done' => false, 'error' => $message, 'seconds' => round(microtime(true) - $t0, 1)];
        } finally {
            $this->unlock();
        }
    }

    /** A TIDAL-only step shares the import lock but never collects music or rebuilds a feed. */
    public function stepTidal(int $budgetSeconds): array
    {
        $t0 = microtime(true);
        if (!$this->lock()) {
            return ['status' => 'busy', 'done' => false, 'mode' => 'tidal_only', 'message' => 'another import call is running'];
        }
        try {
            if ($this->tidal === null) {
                throw new \RuntimeException('TIDAL enrichment is not configured');
            }
            $tidal = ($this->tidal)($this->now(), max(0, $budgetSeconds - 0.5));
            $done = !($tidal['pending'] ?? false);
            return ['status' => $done ? 'done' : 'working', 'done' => $done, 'mode' => 'tidal_only',
                'tidal' => $tidal, 'seconds' => round(microtime(true) - $t0, 1)];
        } catch (Throwable $e) {
            return ['status' => 'error', 'done' => false, 'mode' => 'tidal_only',
                'error' => $this->redact($e::class . ': ' . $e->getMessage()), 'seconds' => round(microtime(true) - $t0, 1)];
        } finally {
            $this->unlock();
        }
    }

    /**
     * Checks whether this server can run the import - changes nothing:
     * PHP limits, outbound HTTPS to MusicBrainz and Last.fm (one request
     * each), the database, and optionally a dry run of the feed calculation
     * (time and memory; nothing is published).
     */
    public function diagnose(bool $benchmark = false): array
    {
        $before = (string) ini_get('max_execution_time');
        $canExtend = @set_time_limit(90);
        $out = ['php' => [
            'version' => PHP_VERSION,
            'max_execution_time' => $before,
            'set_time_limit_allowed' => $canExtend && ini_get('max_execution_time') === '90',
            'memory_limit' => (string) ini_get('memory_limit'),
            'curl' => extension_loaded('curl'),
            'temp_dir_writable' => is_writable(sys_get_temp_dir()),
        ]];
        $probe = function (callable $fn): array {
            $t = microtime(true);
            try {
                $fn();
                return ['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000)];
            } catch (Throwable $e) {
                return ['ok' => false, 'ms' => (int) round((microtime(true) - $t) * 1000), 'error' => $this->redact($e->getMessage())];
            }
        };
        // Uncached, so it really goes over the network.
        $out['musicbrainz'] = $probe(static fn () => (new MusicBrainzClient(null, 0, 10, 3))->lookupArtist('89ad4ac3-39f7-470e-963a-56509c546377'));
        $out['lastfm'] = $probe(fn () => ($this->lastfm)()->artistInfo('Knocked Loose'));
        $out['database'] = $probe(fn () => $this->db->query('SELECT 1')->fetchColumn());
        $out['database']['version'] = (string) $this->db->query('SELECT VERSION()')->fetchColumn();

        if ($benchmark) {
            $out['feed_dry_run'] = $probe(function () use (&$out): void {
                $store = new DiscoveryStore($this->db);
                $profile = $store->latestProfile() ?? throw new \RuntimeException('no profile yet');
                $feedback = FeedbackSet::fromRepository(new FeedbackRepository($this->db));
                $engine = new RecommendationEngine(TasteModel::fromProfile($profile['profile'], $this->engineConfig), $this->engineConfig, $feedback->forEngine());
                $pipeline = new DiscoveryPipeline($store, ($this->mb)(), ($this->lastfm)(), $engine, $profile['profile'], $this->discoveryConfig,
                    interest: $this->interest($store, $profile['profile'], $feedback));
                $final = $pipeline->buildFeed(date('Y-m-d'));
                $out['feed_dry_run_counts'] = ['examined' => $final['stats']['releases_in_window'],
                    'recommended' => count($final['recommended']), 'wildcards' => count($final['wildcards'])];
            });
            $out['feed_dry_run']['memory_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        }
        return $out;
    }

    /**
     * Why the recommendations should be recalculated now - or null if they
     * are current. Each run stores the versions of the data, feedback,
     * profile and algorithm it was built from; any other version makes it due.
     */
    private function feedDue(?array $active, array $versions, string $today): ?string
    {
        if ($active === null) {
            return 'no active recommendations';
        }
        if ($active['window_to'] !== $today) {
            return 'new day';
        }
        $built = json_decode((string) $active['stats'], true)['versions'] ?? null;
        if (!is_array($built)) {
            return 'first run on this server';
        }
        return match (true) {
            $versions['data'] !== ($built['data'] ?? null) => 'new or changed releases',
            $versions['profile'] !== ($built['profile'] ?? null) => 'new music profile',
            $versions['feedback'] !== ($built['feedback'] ?? null) => 'your feedback changed',
            $versions['algorithm'] !== ($built['algorithm'] ?? null) => 'new algorithm version',
            default => null,
        };
    }

    /** A hash of ALGORITHM_FILES: the same deployed code always gives the same version. */
    public static function algorithmVersion(): string
    {
        $api = dirname(__DIR__, 2);
        $files = [];
        foreach (self::ALGORITHM_FILES as $path) {
            $full = "$api/$path";
            if (is_dir($full)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)) as $f) {
                    $files[] = substr($f->getPathname(), strlen($api) + 1);
                }
            } elseif (is_file($full)) {
                $files[] = $path;
            }
        }
        sort($files);
        $hash = hash_init('sha256');
        foreach ($files as $f) {
            hash_update($hash, str_replace('\\', '/', $f) . "\0" . str_replace("\r\n", "\n", (string) file_get_contents("$api/$f")) . "\0");
        }
        return substr(hash_final($hash), 0, 12);
    }

    /** Artist interest from the stored Last.fm library, the profile and your ratings. */
    private function interest(DiscoveryStore $store, array $profile, FeedbackSet $feedback): ArtistInterest
    {
        return new ArtistInterest($profile, $store->listening(), $this->engineConfig['interest'] ?? [], array_values(array_filter($feedback->forEngine()['ratings'] ?? [], static fn ($r) => !($r['not_interested'] ?? false))));
    }

    /** Small per-stage summary for responses and the log. */
    private function compact(array $stages): array
    {
        $keep = ['skipped', 'skipped_fresh', 'skipped_unchanged', 'complete', 'seconds', 'slices_due', 'slices_collected', 'new', 'changed',
            'errors', 'paused', 'checked_artists', 'remaining', 'needed', 'found', 'releases', 'selected', 'artist_calls', 'album_calls',
            'due', 'fetched', 'calls', 'confirmed', 'backoff', 'skipped_backoff'];
        return array_map(static fn ($s) => array_intersect_key($s, array_flip($keep)), $stages);
    }

    private function lock(): bool
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return true;
        }
        // Lock names are global on a MariaDB server (shared hosting!): scope it to this database.
        $s = $this->db->prepare('SELECT GET_LOCK(CONCAT(DATABASE(), ?), 0)');
        $s->execute([':' . self::LOCK]);
        return (int) $s->fetchColumn() === 1;
    }

    private function unlock(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->db->prepare('SELECT RELEASE_LOCK(CONCAT(DATABASE(), ?))')->execute([':' . self::LOCK]);
        }
    }

    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }
        return (string) preg_replace('/(api_key|password|token)=[^&\s]+/i', '$1=[redacted]', $text);
    }

    private function now(): string
    {
        return $this->clock ? ($this->clock)() : date(DATE_ATOM);
    }
}
