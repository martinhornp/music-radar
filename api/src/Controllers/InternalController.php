<?php

declare(strict_types=1);

namespace MusicRadar\Controllers;

use Closure;
use InvalidArgumentException;
use MusicRadar\Auth\Auth;
use MusicRadar\Discovery\DiscoveryStore;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Feedback\FeedbackSet;
use MusicRadar\Feedback\Schema;
use MusicRadar\Http\Request;
use MusicRadar\Http\Response;
use MusicRadar\Import\ImportLog;
use MusicRadar\Import\ImportRunner;
use MusicRadar\LastFm\LastFmClient;
use MusicRadar\Links\TidalImport;
use MusicRadar\Links\TidalLinkStore;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\Taste\ArtistLevels;
use MusicRadar\Taste\ListeningLibrary;
use MusicRadar\TopAlbums\TopAlbums;
use PDO;
use Throwable;

/**
 * Machine endpoints for the daily import (GitHub Actions) and the one-time
 * migration. Not an admin interface: no pages, no free-form database access.
 *
 *   POST /api/internal/import    one time-boxed import step, or TIDAL-only when tidal_from is supplied
 *   GET  /api/internal/status    monitoring summary (incl. artists per level)
 *   GET  /api/internal/diagnostics  can this server run the import? (changes nothing;
 *                                  ?benchmark=1 adds a dry run of the feed calculation)
 *   GET  /api/internal/profile   the music profile in use (for the weekly refresh)
 *   POST /api/internal/profile   store a new music profile
 *   POST /api/internal/library   replace the Last.fm artist library (weekly refresh)
 *   POST /api/internal/migrate   copy discovery data / profile / feed (whitelisted tables)
 *   POST /api/internal/init-login  create the one login user - only while none exists
 *   POST /api/internal/top-albums  add your most played albums that are not stored yet
 *
 * Every call needs the secret token in the X-Import-Token header (never in
 * the URL, so it is not in access logs). Without a configured token, or with
 * a wrong one, every endpoint answers 404 - as if it did not exist.
 */
final class InternalController
{
    private const MAX_BODY = 8 * 1024 * 1024;

    /** @param Closure(): PDO $db */
    public function __construct(
        private readonly array $config,
        private readonly Closure $db,
        private readonly string $apiDir,
    ) {
    }

    public function import(Request $request): Response
    {
        return $this->guarded($request, function () use ($request): Response {
            $data = $request->json();
            $from = $data['tidal_from'] ?? null;
            $recheck = null;
            if (array_key_exists('tidal_from', $data) || array_key_exists('tidal_recheck', $data)) {
                try {
                    if (!is_string($from)) {
                        throw new InvalidArgumentException('tidal_from must be a YYYY-MM-DD date');
                    }
                    TidalLinkStore::validateFrom($from, date(DATE_ATOM));
                    // Optional: recheck every release of one status from that date on.
                    if (array_key_exists('tidal_recheck', $data)) {
                        $status = $data['tidal_recheck'];
                        $since = $data['tidal_since'] ?? null;
                        if (!is_string($status) || !is_string($since)) {
                            throw new InvalidArgumentException('tidal_recheck and tidal_since must be strings');
                        }
                        TidalLinkStore::validateRecheck($status, $since, date(DATE_ATOM));
                        $recheck = ['status' => $status, 'since' => $since];
                    }
                } catch (InvalidArgumentException $e) {
                    return Response::error($e->getMessage(), 400);
                }
            }
            $budget = (int) ($this->config['import']['budget_seconds'] ?? 20);
            ignore_user_abort(true);       // a dropped connection must not stop a write halfway
            @set_time_limit($budget + 60); // ignored where the host does not allow it
            $runner = $this->runner($from, $recheck);
            return Response::json($from !== null ? $runner->stepTidal($budget) : $runner->step($budget));
        });
    }

    public function status(Request $request): Response
    {
        return $this->guarded($request, function () use ($request): Response {
            $pdo = ($this->db)();
            $store = new DiscoveryStore($pdo);
            $feeds = new RecommendationStore($pdo);
            $feeds->install();
            $log = new ImportLog($pdo);
            $log->install();
            Schema::ensure($pdo);
            $profile = $store->latestProfile();
            $failures = $store->failures();
            $runner = $this->runner();
            $last = $log->recent(1)[0] ?? null;
            // What PHP sees of the caller (the GitHub runner, whose public address the
            // import log prints): the same address = the real client address reaches
            // PHP; another one = a shared proxy in between (see ClientAddress).
            $seen = ['remote_addr' => $request->header('remote-addr'), 'forwarded_for_entries' => count(array_filter(explode(',', $request->header('x-forwarded-for'))))];
            return Response::json([
                'now' => date(DATE_ATOM),
                'caller' => $seen,
                'import' => [
                    'last_call' => $last ? ['at' => $last['started_at'], 'status' => $last['status'], 'seconds' => (float) $last['seconds'], 'error' => $last['error']] : null,
                    'last_done' => $log->last(['done'])['started_at'] ?? null,
                    'last_error' => ($e = $log->last(['error'])) ? ['at' => $e['started_at'], 'error' => $e['error']] : null,
                ],
                'recommendations' => [
                    'active' => $feeds->activeRun() ? array_intersect_key($feeds->activeRun(), array_flip(['id', 'source', 'started_at', 'generated_at', 'window_from', 'window_to', 'examined', 'recommended', 'wildcards'])) : null,
                    'runs' => $feeds->runs(5),
                ],
                'data' => $store->counts() + ['data_changed_at' => $store->meta('data_changed_at'), 'known_checked_at' => $store->meta('known_checked_at')],
                'failures' => [
                    'count' => count($failures),
                    'by_stage' => array_count_values(array_column($failures, 'stage')),
                    'recent' => array_map(fn ($f) => ['stage' => $f['stage'], 'attempts' => (int) $f['attempts'], 'at' => $f['last_at'],
                        'message' => $runner->redact(mb_substr((string) $f['message'], 0, 200))], array_slice($failures, 0, 5)),
                ],
                'profile' => $profile ? ['id' => $profile['id'], 'created_at' => $profile['created_at'], 'source' => $profile['source'],
                    'artists' => count($profile['profile']['artists'] ?? [])] : null,
                'listening_library' => $store->listeningSummary(),
                // Artists per level, counted from the stored profile and Last.fm library.
                'artist_levels' => ArtistLevels::summary($profile, $store->listening(),
                    (require $this->apiDir . '/config/recommender.php')['interest'] ?? []),
                'feedback' => FeedbackSet::fromRepository(new FeedbackRepository($pdo))->summary(),
                // TIDAL links over every stored release - is a backfill or recheck worth it?
                'tidal' => $this->tidalOverview($pdo),
            ]);
        });
    }

    public function diagnostics(Request $request): Response
    {
        return $this->guarded($request, fn (): Response => Response::json(
            $this->runner()->diagnose(($request->query['benchmark'] ?? '') === '1'),
        ));
    }

    public function profile(Request $request): Response
    {
        return $this->guarded($request, function (): Response {
            $p = (new DiscoveryStore(($this->db)()))->latestProfile();
            return $p ? Response::json($p['profile']) : Response::error('No profile yet', 404);
        });
    }

    public function saveProfile(Request $request): Response
    {
        return $this->guarded($request, function () use ($request): Response {
            $profile = json_decode($request->body, true);
            if (!is_array($profile) || !is_array($profile['artists'] ?? null) || !is_array($profile['genres'] ?? null)
                || !is_array($profile['families'] ?? null) || count($profile['artists']) === 0) {
                return Response::error('Not a music profile', 400);
            }
            $id = (new DiscoveryStore(($this->db)()))->saveProfile($profile, 'upload', date(DATE_ATOM));
            return Response::json(['saved' => true, 'id' => $id, 'artists' => count($profile['artists'])]);
        });
    }

    /**
     * {"password": "..."} creates the user "martin" - once: while no user
     * exists (afterwards it changes nothing; the password is changed on the
     * website). Called from the "Initialize login" workflow with the
     * password as a GitHub secret, so it is never in Git, code or logs.
     */
    public function initLogin(Request $request): Response
    {
        return $this->guarded($request, function () use ($request): Response {
            $password = json_decode($request->body, true)['password'] ?? null;
            if (!is_string($password) || $password === '') {
                return Response::error('A password is required', 400);
            }
            $auth = new Auth(($this->db)());
            $auth->ensure();
            $created = $auth->createInitialUser('martin', $password, time());
            return Response::json(['created' => $created, 'note' => $created ? 'user martin created' : 'a user exists already - nothing changed']);
        });
    }

    /**
     * {"artists": [{name, lastfm_mbid, mbid, identity, rank, plays: {overall, 12month, 3month}}, ...]}
     * replaces the stored Last.fm artist library (see Taste\ListeningLibrary).
     * Every entry is checked; one bad entry rejects the whole upload.
     */
    /**
     * {"albums": [...]} adds your most played albums that are not stored yet
     * (TopAlbums::resolve(), from the "Import top albums" workflow); albums
     * already in the list are never changed.
     */
    public function saveTopAlbums(Request $request): Response
    {
        return $this->guarded($request, function () use ($request): Response {
            $albums = json_decode($request->body, true)['albums'] ?? null;
            if (!is_array($albums) || $albums === [] || !array_is_list($albums)) {
                return Response::error('Not an album list', 400);
            }
            try {
                $top = new TopAlbums(($this->db)());
                $added = $top->addMissing($albums, date(DATE_ATOM));
            } catch (InvalidArgumentException $e) {
                return Response::error($e->getMessage(), 400);
            }
            return Response::json(['saved' => true, 'added' => $added, 'total' => $top->count()]);
        });
    }

    public function saveLibrary(Request $request): Response
    {
        return $this->guarded($request, function () use ($request): Response {
            $artists = json_decode($request->body, true)['artists'] ?? null;
            if (!is_array($artists) || $artists === [] || !array_is_list($artists)) {
                return Response::error('Not an artist library', 400);
            }
            $id = static fn ($v) => is_string($v) && ($v === '' || preg_match('/^[0-9a-f-]{36}$/', $v));
            $clean = [];
            foreach ($artists as $a) {
                $plays = $a['plays'] ?? null;
                if (!is_array($a) || !is_string($a['name'] ?? null) || trim($a['name']) === '' || mb_strlen($a['name']) > 500
                    || !$id($a['lastfm_mbid'] ?? null) || !$id($a['mbid'] ?? null)
                    || !in_array($a['identity'] ?? null, ['profile', 'lastfm_mbid', 'name_only'], true)
                    || !is_array($plays) || array_filter(ListeningLibrary::PERIODS, static fn ($p) => !is_int($plays[$p] ?? null) || $plays[$p] < 0) !== []) {
                    return Response::error('Invalid artist entry in the library', 400);
                }
                $clean[] = ['name' => trim($a['name']), 'name_key' => ListeningLibrary::key($a['name']), 'lastfm_mbid' => $a['lastfm_mbid'], 'mbid' => $a['mbid'],
                    'identity' => $a['identity'], 'rank' => is_int($a['rank'] ?? null) ? $a['rank'] : null,
                    'plays' => array_intersect_key($plays, array_flip(ListeningLibrary::PERIODS))];
            }
            $n = (new DiscoveryStore(($this->db)()))->replaceListening($clean, 'lastfm:user.getTopArtists', date(DATE_ATOM));
            return Response::json(['saved' => true, 'artists' => $n]);
        });
    }

    /**
     * {"action": "counts"}                         rows per table (+ fingerprints)
     * {"action": "rows", "table": t, "rows": [...]} upsert rows by primary key
     * {"action": "feed", "feed": {...}}             publish a feed (only if none is active)
     */
    public function migrate(Request $request): Response
    {
        return $this->guarded($request, function () use ($request): Response {
            $data = json_decode($request->body, true);
            $pdo = ($this->db)();
            $store = new DiscoveryStore($pdo);
            try {
                return match ($data['action'] ?? null) {
                    'counts' => Response::json(['counts' => $store->counts(), 'fingerprints' => $store->fingerprints()]),
                    'rows' => Response::json(['written' => $store->importRows((string) ($data['table'] ?? ''), (array) ($data['rows'] ?? []))]),
                    'feed' => Response::json($this->publishMigratedFeed($pdo, (array) ($data['feed'] ?? []))),
                    default => Response::error('Unknown action', 400),
                };
            } catch (InvalidArgumentException $e) {
                return Response::error($e->getMessage(), 400);
            }
        });
    }

    private function publishMigratedFeed(PDO $pdo, array $feed): array
    {
        $feeds = new RecommendationStore($pdo);
        $feeds->install();
        if ($feeds->activeRun() !== null) {
            return ['published' => false, 'reason' => 'the database already has active recommendations'];
        }
        if (!is_array($feed['releases'] ?? null) || $feed['releases'] === []) {
            throw new InvalidArgumentException('Feed without releases');
        }
        return ['published' => true, 'run_id' => $feeds->publish($feed, 'migration', ['source' => 'feed.json'], date(DATE_ATOM))];
    }

    /** @return array<string, int|string>|null  link counts over all stored releases, null if unavailable */
    private function tidalOverview(PDO $pdo): ?array
    {
        try {
            $store = new TidalLinkStore($pdo);
            $store->install();
            return $store->stageSummary('1900-01-01', date(DATE_ATOM)) + ['retries' => $store->retryOutlook(date(DATE_ATOM))];
        } catch (Throwable $e) {
            error_log('tidal overview: ' . $e::class);
            return null;
        }
    }

    private function runner(?string $tidalFrom = null, ?array $tidalRecheck = null): ImportRunner
    {
        $c = $this->config;
        $key = (string) ($c['lastfm']['api_key'] ?? '');
        return new ImportRunner(
            ($this->db)(),
            require $this->apiDir . '/config/discovery.php',
            require $this->apiDir . '/config/recommender.php',
            static fn () => new MusicBrainzClient($c['import']['cache_dir'] ?? sys_get_temp_dir() . '/music-radar/musicbrainz', 86_400 * 7, 10, 2),
            static fn () => new LastFmClient($key),
            [$key, (string) ($c['db']['password'] ?? ''), (string) ($c['import']['token'] ?? '')],
            tidal: fn (string $now, float $budget): array => (new TidalImport(($this->db)(),
                new MusicBrainzClient($c['import']['cache_dir'] ?? sys_get_temp_dir() . '/music-radar/musicbrainz', 86_400 * 7, 5, 1)))->step($now, $budget, $tidalFrom, $tidalRecheck),
        );
    }

    /** Token check, body limit and error handling for every internal endpoint. */
    private function guarded(Request $request, Closure $action): Response
    {
        $token = (string) ($this->config['import']['token'] ?? '');
        if (strlen($token) < 32 || !hash_equals($token, $request->header('x-import-token'))) {
            return Response::error('Not found', 404);
        }
        if (strlen($request->body) > self::MAX_BODY) {
            return Response::error('Request too large', 413);
        }
        try {
            return $action();
        } catch (Throwable $e) {
            error_log('internal: ' . $e::class . ': ' . $e->getMessage());
            return Response::error('Internal error', 500);
        }
    }
}
