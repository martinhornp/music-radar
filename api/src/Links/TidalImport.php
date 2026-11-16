<?php

declare(strict_types=1);

namespace MusicRadar\Links;

use DateTimeImmutable;
use MusicRadar\MusicBrainz\MusicBrainzClient;
use MusicRadar\MusicBrainz\MusicBrainzException;
use PDO;
use Throwable;

/** Bounded TIDAL enrichment of stored releases. Never collects music or scores it. */
final class TidalImport
{
    public function __construct(private readonly PDO $db, private readonly MusicBrainzClient $mb)
    {
    }

    /** @param array{status: string, since: string}|null $recheck  see TidalLinkStore::due() */
    public function step(string $now, float $budgetSeconds = 7, ?string $from = null, ?array $recheck = null): array
    {
        if ($from !== null) {
            TidalLinkStore::validateFrom($from, $now);
        }
        $started = hrtime(true);
        $deadline = $started + (int) (max(0, $budgetSeconds) * 1_000_000_000);
        $base = new DateTimeImmutable($now);
        $clock = static fn () => $base->modify('+' . (int) ((hrtime(true) - $started) / 1_000_000_000) . ' seconds')->format(DATE_ATOM);
        $store = new TidalLinkStore($this->db);
        $store->install();
        if ($from !== null) {
            $store->enqueueFrom($from, $now);
        } else {
            $store->sync($now);
        }
        $checked = 0;
        $checkedNew = 0;
        $results = ['verified' => 0, 'missing' => 0, 'ambiguous' => 0, 'error' => 0];
        $status = 'idle';
        $errors = [];
        $ambiguous = []; // release ids, so the log shows which releases the rules could not decide
        $pause = $store->pauseRemaining($now);
        // At most four lookups per short PHP call. There is no daily quota.
        while ($pause === 0 && $checked < 4 && hrtime(true) + 6_200_000_000 < $deadline && ($row = $store->due($clock(), from: $from, recheck: $recheck)) !== null) {
            $store->reserve($row['id'], $clock());
            try {
                // Leave space after the core client's last MusicBrainz request.
                // Subsequent requests also use the client's normal throttle.
                $ready = hrtime(true) + 1_100_000_000;
                while (($remaining = $ready - hrtime(true)) > 0) {
                    usleep((int) ceil($remaining / 1000));
                }
                $unchanged = $row['identity_hash'] === null || $row['identity_hash'] === TidalMatch::identityHash($row, $row['tidal_credits']);
                $maxAge = $row['first_checked_at'] === null && $unchanged ? 86_400 : 0;
                $response = $this->mb->releaseEditions($row['id'], $maxAge);
                $match = TidalMatch::assess($row, $row['tidal_credits'] ?: [['mbid' => $row['artist_mbid']]], $response);
            } catch (Throwable $e) {
                $http = $e instanceof MusicBrainzException ? $e->httpStatus : 0;
                // Store safe diagnostics, never arbitrary exception messages or URLs.
                $code = $http > 0 && $http !== 200 ? 'http_' . $http
                    : ($http === 200 ? 'invalid_response' : ($e instanceof MusicBrainzException ? 'request_failed' : 'unexpected_error'));
                $match = ['status' => 'error', 'error' => $code];
                $errors[] = ['release_id' => $row['id'], 'code' => $code];
                if ($http === 429 || $http >= 500) {
                    $pause = $store->pause($clock(), $e->retryAfterSeconds);
                    // Yield this release beyond the service pause, so a repeatedly
                    // failing item cannot monopolize other due retries either.
                    $match['retry_after'] = max(TidalLinkStore::ERROR_RETRY_DAYS * 86_400, $pause);
                }
            }
            if ($match['status'] !== 'error') {
                $store->serviceRecovered();
            }
            if ($match['status'] === 'ambiguous') {
                $ambiguous[] = $row['id'];
            }
            $results[$match['status']] = ($results[$match['status']] ?? 0) + 1;
            $checkedNew += $row['tidal_first'] ? 1 : 0;
            $store->save($row, $match, $clock());
            $checked++;
            $status = $match['status'];
        }
        $next = $store->due($clock(), true, $from, $recheck);
        $reserved = $next !== null && $store->due($clock(), from: $from, recheck: $recheck) === null
            ? max(0, strtotime($next['reserved_until'] ?? $now) - strtotime($clock())) : 0;
        $result = ['pending' => $next !== null, 'retry_after' => max($pause, $reserved),
            'checked' => $checked, 'status' => $pause > 0 ? 'backoff' : $status,
            'errors' => $errors, 'ambiguous' => $ambiguous,
            // For the log: what this call did and what is left now.
            'results' => $results, 'checked_new' => $checkedNew, 'checked_retries' => $checked - $checkedNew,
            'queue' => $store->dueCounts($clock(), $from, $recheck)];
        return $from !== null ? $result + ['stage' => $store->stageSummary($from, $clock())]
            : $result + ['initial' => $store->summary(), 'backfill' => $store->summary(true)];
    }
}
