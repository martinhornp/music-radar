<?php

declare(strict_types=1);

/**
 * Tests for import-loop.php and import-job.sh (no network beyond localhost):
 * fake servers on 127.0.0.1 and addresses that cannot be reached.
 *
 *   php scripts/test-import-loop.php
 */

$dir = __DIR__;
$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok || $detail === '' ? '' : "\n       $detail") . "\n";
    $failed += $ok ? 0 : 1;
};
$token = str_repeat('t0ken', 8);
$env = ['IMPORT_TOKEN' => $token, 'CONNECT_TIMEOUT' => '2', 'TIMEOUT' => '3', 'RETRY_WAIT' => '1', 'PAUSE' => '0', 'PATH' => getenv('PATH')];

/** Runs a command with extra env; returns [exit code, output, seconds]. */
$run = static function (string $cmd, array $extra = []) use ($env): array {
    $t = microtime(true);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $extra + $env);
    $out = stream_get_contents($pipes[1]);
    return [proc_close($p), $out, microtime(true) - $t];
};
$loop = static fn (string $url, array $extra = []) => $run('php ' . escapeshellarg("$dir/import-loop.php") . ' ' . escapeshellarg($url), $extra);

// A fake import endpoint: MODE=done (working, working, done) | 500 | slow.
$router = sys_get_temp_dir() . '/import-loop-router.php';
file_put_contents($router, <<<'PHP'
<?php
$mode = getenv('MODE');
$count = sys_get_temp_dir() . '/import-loop-calls-' . getenv('PORT');
header('Content-Type: application/json');
if (($_SERVER['HTTP_X_IMPORT_TOKEN'] ?? '') === '') { http_response_code(404); exit; }
if (str_ends_with($_SERVER['REQUEST_URI'], '/status')) {
    echo '{"recommendations":{"active":{"id":7}},"tidal":{"total":100,"approved":30,"missing":50,"ambiguous":2,"error":8,"unchecked":10,"waiting_retry":55}}';
    exit;
}
$n = (int) @file_get_contents($count) + 1;
file_put_contents($count, (string) $n);
if ($mode === '500') { http_response_code(500); echo '{"error":"boom"}'; exit; }
if ($mode === 'slow') { sleep(5); }
if ($mode === 'tidal-date') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (($body['tidal_from'] ?? '') !== '2026-10-30') { http_response_code(400); echo '{"error":"wrong cutoff"}'; exit; }
    echo json_encode(['status' => $n >= 3 ? 'done' : 'working', 'done' => $n >= 3, 'tidal' => ['status' => 'missing', 'checked' => 1,
        'stage' => ['from' => '2026-10-30', 'to' => '2026-11-01', 'total' => 3, 'approved' => 2, 'missing' => 1,
            'ambiguous' => 0, 'error' => 0, 'unchecked' => 0, 'waiting_retry' => 1]]]);
    exit;
}
if ($mode === 'tidal-recheck') {
    // The same status and start time on every call, so a release is rechecked once per run.
    $body = json_decode(file_get_contents('php://input'), true);
    $first = $count . '.since';
    if (!is_file($first)) { file_put_contents($first, (string) ($body['tidal_since'] ?? '')); }
    if (($body['tidal_recheck'] ?? '') !== 'missing' || ($body['tidal_since'] ?? '') !== file_get_contents($first)
        || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['tidal_since'])) {
        http_response_code(400); echo '{"error":"wrong recheck"}'; exit;
    }
    echo json_encode(['status' => $n >= 2 ? 'done' : 'working', 'done' => $n >= 2, 'tidal' => ['status' => 'ambiguous', 'checked' => 4,
        'ambiguous' => $n === 1 ? ['album-7', 'album-8'] : ['album-9']]]);
    exit;
}
if ($mode === 'tidal-limit' || $mode === 'music-limit') {
    // Never "done": the music part is done (tidal-limit) or not (music-limit), TIDAL work keeps coming.
    echo json_encode(['status' => 'working', 'done' => false, 'music_done' => $mode === 'tidal-limit', 'tidal' => ['status' => 'missing',
        'checked' => 4, 'checked_new' => $n === 1 ? 2 : 0, 'checked_retries' => $n === 1 ? 2 : 4,
        'results' => ['verified' => 1, 'missing' => 3, 'ambiguous' => 0, 'error' => 0], 'queue' => ['new' => 0, 'retries' => 6]]]);
    exit;
}
if ($mode === 'tidal-backoff' && $n < 3) {
    echo json_encode(['status' => 'working', 'done' => false, 'tidal' => ['status' => 'backoff', 'checked' => 1,
        'retry_after' => 1, 'errors' => [['release_id' => 'album-1', 'code' => 'http_503']]]]);
    exit;
}
if ($mode === 'tidal-infrastructure') {
    echo json_encode(['status' => 'done', 'done' => true, 'tidal' => ['status' => 'error', 'deferred' => true]]);
    exit;
}
if ($mode === 'tidal-backfill') {
    echo json_encode(['status' => 'done', 'done' => true, 'tidal' => ['status' => 'missing', 'checked' => 4,
        'backfill' => ['run_id' => 29, 'checked' => 370, 'total' => 374, 'verified' => 180, 'missing' => 189,
            'ambiguous' => 0, 'error' => 1, 'pending' => 4]]]);
    exit;
}
if ($mode === 'tidal-finished') {
    echo json_encode(['status' => 'done', 'done' => true, 'tidal' => ['status' => 'missing', 'checked' => 4,
        'initial' => ['checked' => 54, 'total' => 54, 'verified' => 27, 'missing' => 27, 'ambiguous' => 0, 'error' => 0, 'pending' => 0],
        'backfill' => ['run_id' => 29, 'checked' => 374, 'total' => 374, 'verified' => 180, 'missing' => 193,
            'ambiguous' => 0, 'error' => 1, 'pending' => 0]]]);
    exit;
}
echo json_encode(['status' => $n >= 3 ? 'done' : 'working', 'done' => $n >= 3, 'seconds' => 0.1, 'stages' => []]);
PHP);
$servers = [];
$server = static function (string $mode) use (&$servers, $router): string {
    $port = 18000 + count($servers) + random_int(0, 900);
    @unlink(sys_get_temp_dir() . "/import-loop-calls-$port");
    $servers[] = proc_open(['php', '-S', "127.0.0.1:$port", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
        ['MODE' => $mode, 'PORT' => (string) $port]);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100_000);
    }
    return "http://127.0.0.1:$port";
};

echo "The server cannot be reached (before a request is sent)\n";
[$code, $out, $secs] = $loop('http://127.0.0.1:1');
$check('connection refused: exit 75 ("try a new runner")', $code === 75, "exit $code\n$out");
$check('...says it failed in the TCP phase', str_contains($out, 'no answer - tcp:'), $out);
$check('...retries on this runner first (5 calls)', substr_count($out, 'no answer') === 5, $out);
[$code, $out, $secs] = $loop('http://10.255.255.1');
$check('no answer at all (dropped connection attempt): exit 75 after the connect timeout, not minutes', $code === 75 && $secs < 40, sprintf("exit %d in %.0fs\n%s", $code, $secs, $out));
$check('...TCP phase', str_contains($out, 'no answer - tcp:'), $out);
[$code, $out] = $loop('http://no-such-host.invalid');
$check('DNS failure: exit 75, DNS phase', $code === 75 && str_contains($out, 'no answer - dns:'), "exit $code\n$out");
$plain = $server('done');
[$code, $out] = $loop(str_replace('http:', 'https:', $plain));
$check('TLS failure: exit 75, TLS phase', $code === 75 && str_contains($out, 'no answer - tls:'), "exit $code\n$out");
$check('the summary says the server was not reached', str_contains($out, 'Could not reach the server from this runner'), $out);

echo "The server answered or may have received the call\n";
[$code, $out] = $loop($plain);
$check('a normal import: done after 3 calls, exit 0', $code === 0 && substr_count($out, ': working') === 2 && str_contains($out, 'call 3: done'), "exit $code\n$out");
[$code, $out, $secs] = $loop($server('tidal-backoff'));
$check('TIDAL backoff pauses between calls and resumes the queue', $code === 0 && $secs >= 2 && str_contains($out, 'call 3: done'), "exit $code in $secs\n$out");
$check('TIDAL item errors are reported separately from the core import', str_contains($out, 'album-1 (http_503)')
    && str_contains($out, '1 releases with lookup errors'), $out);
[$code, $out] = $loop($server('tidal-infrastructure'));
$check('TIDAL infrastructure failure is reported without claiming a drained queue', $code === 1
    && str_contains($out, 'done with problems') && str_contains($out, 'queue deferred'), "exit $code\n$out");
[$code, $out] = $loop($server('tidal-backfill'));
$check('the workflow reports the frozen active-feed backfill separately while it has work left', $code === 0
    && str_contains($out, '- TIDAL backfill run 29: 370/374 checked, 180 verified, 189 missing, 0 ambiguous, 1 errors, 4 pending'), "exit $code\n$out");
[$code, $out] = $loop($server('tidal-finished'));
$check('finished one-time reviews are not repeated in every log', $code === 0
    && !str_contains($out, 'TIDAL initial') && !str_contains($out, 'TIDAL backfill run') && str_contains($out, '- TIDAL: missing'), "exit $code\n$out");
[$code, $out] = $loop($server('tidal-limit'), ['MAX_CALLS' => '3']);
$check('reaching the call limit with only TIDAL work left is not an error', $code === 0 && str_contains($out, '## Daily import: done')
    && str_contains($out, '- Music import: done') && str_contains($out, 'not an error, it continues in the next run'), "exit $code\n$out");
$check('...and the log says how far it got: new first, then retries', str_contains($out,
    '- TIDAL queue: new releases 2 of 2 checked, retries 10 of 16 checked - 6 left for the next run')
    && str_contains($out, '- TIDAL links: 12 checked - 3 new links, 9 still missing, 0 ambiguous, 0 lookup errors')
    && str_contains($out, 'TIDAL so far: 4 checked - 1 new links, 3 still missing, 0 ambiguous, 0 errors | 6 left (0 new, 6 retries)')
    && str_contains($out, 'TIDAL so far: 12 checked - 3 new links, 9 still missing, 0 ambiguous, 0 errors | 6 left (0 new, 6 retries)'), $out);
$check('the summary ends with the TIDAL overview of the whole database', str_contains($out,
    '- TIDAL overall: 100 releases - 30 with a link, 50 missing, 2 ambiguous, 8 errors, 10 never checked; 5 due for a retry now'), $out);
[$code, $out] = $loop($server('music-limit'), ['MAX_CALLS' => '3']);
$check('...but the music import itself not finishing is still an error', $code === 1 && str_contains($out, 'NOT finished')
    && str_contains($out, '- Music import: NOT finished'), "exit $code\n$out");
[$code, $out] = $loop($server('tidal-recheck'), ['TIDAL_FROM' => '2026-10-30', 'TIDAL_CHECK' => 'missing']);
$check('a recheck sends one status and one start time on every call', $code === 0 && str_contains($out, 'call 2: done')
    && str_contains($out, '- TIDAL check: every missing release from 2026-10-30 on'), "exit $code
$out");
$check('ambiguous releases are listed by id, per call and in the summary', str_contains($out, 'TIDAL ambiguous: album-8')
    && str_contains($out, '- TIDAL ambiguous during this run (3): album-7, album-8, album-9'), $out);
[$code, $out] = $loop('http://127.0.0.1:1', ['TIDAL_ONLY' => 'true', 'TIDAL_FROM' => '2026-10-30', 'TIDAL_CHECK' => 'everything']);
$check('an unknown check is refused before any call', $code === 2 && !str_contains($out, 'call 1:'), "exit $code
$out");
[$code, $out] = $loop($server('tidal-date'), ['TIDAL_FROM' => '2026-10-30']);
$check('a date stage sends the same cutoff on every resumed import call', $code === 0 && str_contains($out, 'call 3: done'), "exit $code\n$out");
$check('the workflow reports date scope, reused approvals and waiting retries', str_contains($out,
    '- TIDAL date stage 2026-10-30 - 2026-11-01: 3 total, 2 approved, 1 missing, 0 ambiguous, 0 errors, 0 unchecked, 1 waiting for retry'), $out);
$check('a standalone date stage does not request regular-import monitoring', str_contains($out, '## TIDAL backfill: done')
    && !str_contains($out, 'Recommendations:') && !str_contains($out, 'Status not available'), $out);
[$code, $out] = $loop('http://127.0.0.1:1', ['TIDAL_ONLY' => 'true', 'TIDAL_FROM' => '']);
$check('a missing standalone cutoff cannot fall back to a full music import', $code === 2 && !str_contains($out, 'call 1:'), "exit $code\n$out");
[$code, $out] = $loop('http://127.0.0.1:1', ['TIDAL_FROM' => '2026-02-30']);
$check('invalid calendar dates are rejected before an HTTP request', $code === 2 && !str_contains($out, 'call 1:'), "exit $code\n$out");
[$code, $out] = $loop($server('500'));
$check('HTTP 500 from the server: exit 1, no new runner', $code === 1 && str_contains($out, 'HTTP 500'), "exit $code\n$out");
[$code, $out] = $loop($server('slow'));
$check('connected but no answer in time: exit 1, no new runner (the call may have been processed)', $code === 1 && str_contains($out, 'no answer - timeout:'), "exit $code\n$out");
$check('the token is never printed', !str_contains($out, $token));

echo "import-job.sh (one runner)\n";
$job = static fn (string $url, array $extra = []) => $run('bash ' . escapeshellarg("$dir/import-job.sh"), ['SITE_URL' => $url] + $extra);
$outFile = tempnam(sys_get_temp_dir(), 'gh-output');
[$code, $out] = $job('http://127.0.0.1:1', ['GITHUB_OUTPUT' => $outFile]);
$check('unreachable: the attempt succeeds and asks for a new runner', $code === 0 && trim((string) file_get_contents($outFile)) === 'retry=true'
    && str_contains($out, '::warning::Could not reach the server'), "exit $code\n$out");
$check('...and logs the runner address (or "unknown")', preg_match('/Runner public IPv4: (unknown|\d+\.\d+\.\d+\.\d+)\n/', $out) === 1, $out);
file_put_contents($outFile, '');
[$code, $out] = $job('http://127.0.0.1:1', ['GITHUB_OUTPUT' => $outFile, 'LAST_ATTEMPT' => 'true']);
$check('the last attempt fails instead (no endless retries)', $code === 1 && file_get_contents($outFile) === '' && str_contains($out, '::error::'), "exit $code\n$out");
[$code, $out] = $job($server('500'), ['GITHUB_OUTPUT' => $outFile]);
$check('an HTTP error fails at once, no new runner', $code === 1 && file_get_contents($outFile) === '', "exit $code\n$out");
[$code, $out] = $job($server('done'), ['GITHUB_OUTPUT' => $outFile]);
$check('a normal import passes, no new runner', $code === 0 && file_get_contents($outFile) === '', "exit $code\n$out");
[$code, $out] = $run('bash ' . escapeshellarg("$dir/import-job.sh"), ['SITE_URL' => 'http://127.0.0.1:1', 'IMPORT_TOKEN' => '']);
$check('without the token: clear error', $code === 1 && str_contains($out, 'IMPORT_TOKEN is missing'), $out);

foreach ($servers as $s) {
    proc_terminate($s);
}
echo $failed === 0 ? "All tests passed.\n" : "$failed test(s) FAILED.\n";
exit($failed === 0 ? 0 : 1);
