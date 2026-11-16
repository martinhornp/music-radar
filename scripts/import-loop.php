<?php

declare(strict_types=1);

/**
 * Drives the daily import, or a TIDAL-only job when TIDAL_FROM is set:
 * calls POST /api/internal/import again and again
 * (each call does ~20 s of work on the server) until the server says "done",
 * then prints progress. Used by daily-import.yml and tidal-backfill.yml.
 * A date request selects the server's TIDAL-only path and skips music import.
 * TIDAL_CHECK (new = default, errors, ambiguous, missing) rechecks every release
 * of that status from the date on - each once, since the start of this run.
 *
 *   IMPORT_TOKEN=... php scripts/import-loop.php https://musik.hornpedersen.dk
 *
 * Env: IMPORT_TOKEN (required), MAX_CALLS (150), PAUSE (4 s between calls -
 * the host rate-limits bursts), MAX_MINUTES (50), CONNECT_TIMEOUT (10 s to
 * reach the server), TIMEOUT (170 s for a whole call), RETRY_WAIT (15 s,
 * growing per failed call). Exit code 0 = done, 1 = not finished or errors
 * (the workflow then fails and GitHub notifies), 75 = the server could not be
 * reached from this runner (DNS, TCP or TLS failed before a request was sent)
 * - the workflow may then continue on a new runner. Repeating a call is safe
 * either way: each call continues from the stored state, under a lock.
 * The token is sent as a header and never printed.
 */

$base = rtrim($argv[1] ?? '', '/');
$token = (string) getenv('IMPORT_TOKEN');
if (!preg_match('#^https?://#', $base) || strlen($token) < 32) {
    fwrite(STDERR, "Usage: IMPORT_TOKEN=... php import-loop.php <site-url>\n");
    exit(2);
}
$maxCalls = (int) (getenv('MAX_CALLS') ?: 150);
$pause = (float) (getenv('PAUSE') ?: 4);
$deadline = time() + 60 * (int) (getenv('MAX_MINUTES') ?: 50);
$connectTimeout = (int) (getenv('CONNECT_TIMEOUT') ?: 10);
$timeout = (int) (getenv('TIMEOUT') ?: 170);
$retryWait = (int) (getenv('RETRY_WAIT') ?: 15);
$tidalFrom = (string) getenv('TIDAL_FROM');
$tidalOnly = getenv('TIDAL_ONLY') === 'true' || $tidalFrom !== '';
if ($tidalOnly && $tidalFrom === '') {
    fwrite(STDERR, "TIDAL_FROM is required for a TIDAL-only run\n");
    exit(2);
}
if ($tidalFrom !== '') {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $tidalFrom);
    $today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Copenhagen')))->format('Y-m-d');
    if ($date === false || $date->format('Y-m-d') !== $tidalFrom || $tidalFrom > $today) {
        fwrite(STDERR, "TIDAL_FROM must be a valid YYYY-MM-DD date no later than today\n");
        exit(2);
    }
}
$tidalCheck = (string) (getenv('TIDAL_CHECK') ?: 'new');
$recheck = ['new' => null, 'errors' => 'error', 'ambiguous' => 'ambiguous', 'missing' => 'missing'];
if (!array_key_exists($tidalCheck, $recheck) || ($recheck[$tidalCheck] !== null && $tidalFrom === '')) {
    fwrite(STDERR, "TIDAL_CHECK must be new, errors, ambiguous or missing (with TIDAL_FROM)\n");
    exit(2);
}
$request = $tidalFrom !== '' ? ['tidal_from' => $tidalFrom] : [];
if ($recheck[$tidalCheck] !== null) {
    // The same start time on every call: a release checked during this run is not taken again.
    $request += ['tidal_recheck' => $recheck[$tidalCheck], 'tidal_since' => gmdate('Y-m-d\TH:i:s\Z')];
}
$postBody = $request !== [] ? json_encode($request, JSON_THROW_ON_ERROR) : '{}';
if (!extension_loaded('curl')) {
    fwrite(STDERR, "The PHP curl extension is required\n");
    exit(1);
}

/**
 * One request: [HTTP status, decoded body, failure]. The failure is null when
 * the server answered (any status); otherwise it says in which phase the call
 * failed (dns, tcp, tls, timeout, network) and whether the request may have
 * reached the server ('sent' - only false when it surely did not).
 */
$call = static function (string $method, string $path) use ($base, $token, $connectTimeout, $timeout, $postBody): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', "X-Import-Token: $token", 'User-Agent: MartinsMusicRadar import loop'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
    ] + ($method === 'POST' ? [CURLOPT_POSTFIELDS => $postBody] : []));
    $body = curl_exec($ch);
    if (is_string($body)) {
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($body, true), null];
    }
    $errno = curl_errno($ch);
    $phase = match (true) {
        in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY], true) => 'dns',
        curl_getinfo($ch, CURLINFO_CONNECT_TIME) == 0 => 'tcp',
        str_starts_with($base, 'https:') && curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) == 0 => 'tls',
        $errno === CURLE_OPERATION_TIMEOUTED => 'timeout',
        default => 'network',
    };
    // curl's message names host, port and time - never the request headers.
    return [0, null, ['phase' => $phase, 'sent' => !in_array($phase, ['dns', 'tcp', 'tls'], true), 'message' => curl_error($ch) . " (curl $errno)"]];
};
$line = static fn (string $m) => print(date('H:i:s') . " $m\n");

$done = false;
$errors = 0;
$unreached = 0; // failed calls in the current streak that surely never reached the server
$published = [];
$hadError = false;
$tidalErrors = [];
$tidalAmbiguous = [];
$tidalStatus = null;
$tidalDeferred = false;
$tidalInitial = null;
$tidalBackfill = null;
$tidalStage = null;
$musicDone = false;                                    // collecting + feed complete (server says so)
$tidalChecked = ['new' => 0, 'retries' => 0];          // TIDAL lookups during this run
$tidalResults = ['verified' => 0, 'missing' => 0, 'ambiguous' => 0, 'error' => 0];
$tidalQueue = null;                                    // left to check after the last call
// Per service: work that succeeded vs failed during this run.
$service = ['MusicBrainz' => ['ok' => 0, 'failed' => 0], 'Last.fm' => ['ok' => 0, 'failed' => 0]];
for ($i = 1; $i <= $maxCalls && time() < $deadline; $i++) {
    [$status, $r, $failure] = $call('POST', '/api/internal/import');
    if ($status !== 200 || !is_array($r)) {
        $errors++;
        $unreached += $failure !== null && !$failure['sent'] ? 1 : 0;
        $line("call $i: " . ($failure !== null ? "no answer - {$failure['phase']}: {$failure['message']}"
            : "HTTP $status" . ($status === 429 ? ' (rate limited by the host)' : '')));
        if ($errors >= 5) {
            break;
        }
        sleep(min(60, $retryWait * $errors));
        continue;
    }
    $stages = implode(' ', array_map(
        static fn ($name, $s) => $name . (isset($s['skipped']) ? ':later' : (($s['complete'] ?? true) ? ':ok' : ':part')),
        array_keys($r['stages'] ?? []), $r['stages'] ?? [],
    ));
    $feed = $r['feed'] ?? null;
    $line(sprintf('call %d: %s %.1fs %s%s', $i, $r['status'], $r['seconds'] ?? 0, $stages,
        $feed ? ' | feed: ' . (($feed['published'] ?? false) ? "published run {$feed['run_id']} ({$feed['recommended']} + {$feed['wildcards']}, {$feed['reason']})" : $feed['reason']) : ''));
    $musicDone = $musicDone || ($r['music_done'] ?? false);
    if (is_array($r['tidal'] ?? null)) {
        $tidalStatus = $r['tidal']['status'] ?? 'idle';
        $tidalDeferred = $r['tidal']['deferred'] ?? false;
        // The one-time reviews are only worth a line while they still have work left.
        $pending = static fn (?array $job) => $job !== null && ($job['pending'] ?? 0) > 0 ? $job : null;
        $tidalInitial = $pending($r['tidal']['initial'] ?? null) ?? $tidalInitial;
        $tidalBackfill = $pending($r['tidal']['backfill'] ?? null) ?? $tidalBackfill;
        $tidalStage = $r['tidal']['stage'] ?? $tidalStage;
        foreach ($r['tidal']['errors'] ?? [] as $error) {
            $tidalErrors[$error['release_id']] = $error['code'];
            $line('  TIDAL lookup failed: ' . $error['release_id'] . ' (' . $error['code'] . ')');
        }
        foreach ($r['tidal']['ambiguous'] ?? [] as $releaseId) {
            $tidalAmbiguous[$releaseId] = true;
            $line('  TIDAL ambiguous: ' . $releaseId);
        }
        if (isset($r['tidal']['queue'])) {
            $t = $r['tidal'];
            $tidalChecked['new'] += $t['checked_new'] ?? 0;
            $tidalChecked['retries'] += $t['checked_retries'] ?? 0;
            foreach ($t['results'] ?? [] as $k => $n) {
                $tidalResults[$k] = ($tidalResults[$k] ?? 0) + $n;
            }
            $tidalQueue = $t['queue'];
            // Running totals for this run, so the numbers grow while "left" shrinks.
            $line(sprintf('  TIDAL so far: %d checked - %d new links, %d still missing, %d ambiguous, %d errors | %d left (%d new, %d retries)%s',
                $tidalChecked['new'] + $tidalChecked['retries'], $tidalResults['verified'], $tidalResults['missing'], $tidalResults['ambiguous'],
                $tidalResults['error'], $tidalQueue['new'] + $tidalQueue['retries'], $tidalQueue['new'], $tidalQueue['retries'],
                ($t['status'] ?? '') === 'backoff' ? ' - MusicBrainz asked to wait' : ''));
        } else {
            $line('  TIDAL: ' . ($r['tidal']['status'] ?? 'idle') . ', checked ' . ($r['tidal']['checked'] ?? 0));
        }
        if ($initial = $pending($r['tidal']['initial'] ?? null)) {
            $line(sprintf('  TIDAL initial: %d/%d checked, %d verified, %d missing, %d ambiguous, %d errors',
                $initial['checked'], $initial['total'], $initial['verified'], $initial['missing'], $initial['ambiguous'], $initial['error']));
        }
        if ($backfill = $pending($r['tidal']['backfill'] ?? null)) {
            $line(sprintf('  TIDAL backfill run %s: %d/%d checked, %d verified, %d missing, %d ambiguous, %d errors, %d pending',
                $backfill['run_id'] ?? '-', $backfill['checked'], $backfill['total'], $backfill['verified'],
                $backfill['missing'], $backfill['ambiguous'], $backfill['error'], $backfill['pending']));
        }
        if ($stage = $r['tidal']['stage'] ?? null) {
            $line(sprintf('  TIDAL date stage %s - %s: %d total, %d approved, %d missing, %d ambiguous, %d errors, %d unchecked, %d waiting for retry',
                $stage['from'], $stage['to'], $stage['total'], $stage['approved'], $stage['missing'], $stage['ambiguous'],
                $stage['error'], $stage['unchecked'], $stage['waiting_retry']));
        }
    }
    if ($feed['published'] ?? false) {
        $published[] = $feed;
    }
    $st = $r['stages'] ?? [];
    $service['MusicBrainz']['ok'] += ($st['collect']['slices_collected'] ?? 0) + ($st['known']['checked_artists'] ?? 0) + ($st['artists']['found'] ?? 0);
    $service['MusicBrainz']['failed'] += ($st['collect']['errors'] ?? 0) + ($st['known']['errors'] ?? 0) + ($st['artists']['errors'] ?? 0);
    $service['Last.fm']['ok'] += ($st['lastfm']['artist_calls'] ?? 0) + ($st['lastfm']['album_calls'] ?? 0);
    $service['Last.fm']['failed'] += $st['lastfm']['errors'] ?? 0;
    if ($r['status'] === 'error') {
        $hadError = true;
        $errors++;
        $line('  error: ' . ($r['error'] ?? 'unknown'));
        if ($errors >= 3) {
            break;
        }
    } elseif ($r['status'] === 'busy') {
        sleep(30); // another call is still running on the server
    } else {
        $errors = 0;
        $unreached = 0;
    }
    if ($r['done'] ?? false) {
        $done = true;
        break;
    }
    // Persisted service backoff also applies across PHP calls and workflow restarts.
    usleep((int) (max($pause, min(60, $r['tidal']['retry_after'] ?? 0)) * 1_000_000));
}

// A service that failed for everything it was asked is a problem, even if
// the import "finished" (the failed items are paused and retried tomorrow).
$down = array_keys(array_filter($service, static fn ($x) => $x['failed'] > 0 && $x['ok'] === 0));
// Stopped at the call/time limit with only TIDAL links left: not an error - the
// queue simply continues in the next run (newest releases first).
$tidalLeft = $tidalQueue !== null ? $tidalQueue['new'] + $tidalQueue['retries'] : 0;
$onlyTidalLeft = !$done && $errors === 0 && !$hadError && ($musicDone || $tidalOnly) && $tidalLeft > 0;
$done = $done || $onlyTidalLeft;
$ok = $done && !$hadError && $down === [] && !$tidalDeferred;
// Stopped because every call in the last streak failed before reaching the server.
$unreachable = !$done && $errors > 0 && $unreached === $errors;

// Monitoring summary (also into the GitHub Actions run summary).
[$status, $s] = $tidalOnly ? [0, null] : $call('GET', '/api/internal/status');
$summary = ['## ' . ($tidalOnly ? 'TIDAL backfill' : 'Daily import') . ': ' . ($ok ? 'done' : ($done ? 'done with problems' : 'NOT finished')), ''];
if (isset($request['tidal_recheck'])) {
    $summary[] = "- TIDAL check: every {$request['tidal_recheck']} release from $tidalFrom on, checked before {$request['tidal_since']}";
}
if (!$tidalOnly) {
    $summary[] = '- Music import: ' . ($musicDone ? 'done' : 'NOT finished') . ($published !== [] ? ' - new recommendations published (run '
        . end($published)['run_id'] . ')' : ($musicDone ? ' - nothing new to publish' : ''));
}
if ($tidalQueue !== null) {
    $newTotal = $tidalChecked['new'] + $tidalQueue['new'];
    $retryTotal = $tidalChecked['retries'] + $tidalQueue['retries'];
    $summary[] = sprintf('- TIDAL links: %d checked - %d new link%s, %d still missing, %d ambiguous, %d lookup errors',
        $tidalChecked['new'] + $tidalChecked['retries'], $tidalResults['verified'], $tidalResults['verified'] === 1 ? '' : 's',
        $tidalResults['missing'], $tidalResults['ambiguous'], $tidalResults['error']);
    $summary[] = sprintf('- TIDAL queue: new releases %d of %d checked, retries %d of %d checked', $tidalChecked['new'], $newTotal,
        $tidalChecked['retries'], $retryTotal) . ($tidalLeft > 0 ? " - $tidalLeft left for the next run" : ' - all done');
} else {
    $summary[] = '- TIDAL: ' . ($tidalStatus ?? 'not reached') . ', ' . count($tidalErrors) . ' releases with lookup errors during this run';
}
if ($tidalDeferred) {
    $summary[] = '- TIDAL: infrastructure failure, queue deferred to a later run';
}
if ($onlyTidalLeft) {
    $summary[] = "- Stopped at the time/call limit with TIDAL work left - not an error, it continues in the next run";
}
if ($tidalAmbiguous !== []) {
    $summary[] = '- TIDAL ambiguous during this run (' . count($tidalAmbiguous) . '): ' . implode(', ', array_keys($tidalAmbiguous));
}
if ($tidalInitial !== null) {
    $summary[] = sprintf('- TIDAL initial: %d/%d checked, %d verified, %d missing, %d ambiguous, %d errors, %d pending',
        $tidalInitial['checked'], $tidalInitial['total'], $tidalInitial['verified'], $tidalInitial['missing'],
        $tidalInitial['ambiguous'], $tidalInitial['error'], $tidalInitial['pending']);
}
if ($tidalBackfill !== null) {
    $summary[] = sprintf('- TIDAL backfill run %s: %d/%d checked, %d verified, %d missing, %d ambiguous, %d errors, %d pending',
        $tidalBackfill['run_id'] ?? '-', $tidalBackfill['checked'], $tidalBackfill['total'], $tidalBackfill['verified'],
        $tidalBackfill['missing'], $tidalBackfill['ambiguous'], $tidalBackfill['error'], $tidalBackfill['pending']);
}
if ($tidalStage !== null) {
    $summary[] = sprintf('- TIDAL date stage %s - %s: %d total, %d approved, %d missing, %d ambiguous, %d errors, %d unchecked, %d waiting for retry',
        $tidalStage['from'], $tidalStage['to'], $tidalStage['total'], $tidalStage['approved'], $tidalStage['missing'], $tidalStage['ambiguous'],
        $tidalStage['error'], $tidalStage['unchecked'], $tidalStage['waiting_retry']);
}
if ($unreachable) {
    $summary[] = "- **Could not reach the server from this runner** ($unreached calls failed before a request was sent)";
}
foreach ($tidalOnly ? [] : $service as $name => $x) {
    $summary[] = sprintf('- %s: %d ok, %d failed%s', $name, $x['ok'], $x['failed'], in_array($name, $down, true) ? ' - **could not be reached**' : '');
}
if (is_array($s)) {
    $active = $s['recommendations']['active'] ?? null;
    $summary[] = sprintf('- Recommendations: run %s, %d Recommended for You + %d Wildcards, window %s - %s (%s)',
        $active['id'] ?? '-', $active['recommended'] ?? 0, $active['wildcards'] ?? 0, $active['window_from'] ?? '?', $active['window_to'] ?? '?', $active['source'] ?? '');
    $summary[] = '- Published this time: ' . ($published ? implode(', ', array_map(static fn ($f) => "run {$f['run_id']} ({$f['reason']})", $published)) : 'nothing new');
    $summary[] = sprintf('- Data: %d releases, %d artists; last data change %s', $s['data']['releases'] ?? 0, $s['data']['artists'] ?? 0, $s['data']['data_changed_at'] ?? '-');
    $summary[] = sprintf('- Failures waiting for retry: %d %s', $s['failures']['count'] ?? 0, json_encode($s['failures']['by_stage'] ?? []));
    foreach ($s['failures']['recent'] ?? [] as $f) {
        $summary[] = "  - {$f['stage']} ({$f['attempts']}x, {$f['at']}): {$f['message']}";
    }
    $summary[] = '- Profile: ' . (($s['profile']['created_at'] ?? null) ? "{$s['profile']['created_at']} ({$s['profile']['artists']} artists)" : 'none');
    $summary[] = '- Feedback: ' . json_encode($s['feedback'] ?? []);
    if (isset($s['caller'])) {
        // Compare with "Runner public IPv4" above: the same = PHP sees real client addresses.
        $summary[] = sprintf('- This runner as PHP sees it: %s (X-Forwarded-For entries: %d)', $s['caller']['remote_addr'] ?: '-', $s['caller']['forwarded_for_entries'] ?? 0);
    }
    if ($l = $s['artist_levels'] ?? null) {
        $bad = $l['top100_check']['not_interested'] ?? [];
        $summary[] = sprintf('- Artists: %d Following, %d Interested, %d Listened, taste profile %d, unique top 100 %d; top 100 all Interested/Following: %s%s',
            $l['following'], $l['interested'], $l['listened'], $l['taste_artists'] ?? $l['taste_top30'] ?? 0, $l['top100_unique'], ($l['top100_check']['ok'] ?? false) ? 'yes' : 'NO',
            $bad ? ' - not Interested: ' . implode(', ', array_map(static fn ($a) => "{$a['name']} ({$a['mbid']}, {$a['level']})", $bad)) : '');
    }
    if ($s['import']['last_error'] ?? null) {
        $summary[] = "- Last error: {$s['import']['last_error']['at']}: {$s['import']['last_error']['error']}";
    }
    if ($t = $s['tidal'] ?? null) {
        // The whole database, not this run: is a backfill or a recheck worth it?
        $summary[] = sprintf('- TIDAL overall: %d releases - %d with a link, %d missing, %d ambiguous, %d errors, %d never checked; %d due for a retry now',
            $t['total'], $t['approved'], $t['missing'], $t['ambiguous'], $t['error'], $t['unchecked'],
            max(0, $t['missing'] + $t['ambiguous'] + $t['error'] - $t['waiting_retry']));
    }
} elseif (!$tidalOnly) {
    $summary[] = "- Status not available (HTTP $status)";
}
$text = implode("\n", $summary) . "\n";
echo "\n$text";
if ($file = getenv('GITHUB_STEP_SUMMARY')) {
    file_put_contents($file, $text, FILE_APPEND);
}
exit($ok ? 0 : ($unreachable ? 75 : 1));
