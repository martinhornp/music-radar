<?php

declare(strict_types=1);

/**
 * Tests for the login (one user): password hashing, sessions, failed-login
 * limits, logout, password change and the one-time user creation.
 *
 * Runs against a SEPARATE test database (default music_radar_test) - never
 * the database the site uses.
 *
 *   docker compose exec php php /var/www/api/bin/test-auth.php
 */

use MusicRadar\Auth\Auth;
use MusicRadar\Controllers\AuthController;
use MusicRadar\Controllers\InternalController;
use MusicRadar\Database\Database;
use MusicRadar\Http\ClientAddress;
use MusicRadar\Http\Request;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/../bootstrap.php';
$dbCfg = ['name' => getenv('TEST_DB_NAME') ?: 'music_radar_test'] + $app->config['db'];
if (!str_ends_with($dbCfg['name'], '_test')) {
    fwrite(STDERR, "Refusing to run: the test database name must end in _test.\n");
    exit(2);
}

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === '' ? '' : " - $detail") . PHP_EOL;
    $failures += $ok ? 0 : 1;
}

$pdo = Database::connect($dbCfg);
$pdo->exec('DROP TABLE IF EXISTS users, auth_sessions, login_attempts');
$now = 1_800_000_000;
$clock = static function () use (&$now): int {
    return $now;
};
$auth = new Auth($pdo);
$ctl = new AuthController(static fn () => $auth, true, $clock);
$req = static fn (string $path, array $body = [], string $cookie = '', string $ip = '10.0.0.1', array $h = []) => new Request('POST', $path, [], json_encode($body), [],
    $h + ['content-type' => 'application/json', 'cookie' => $cookie, 'remote-addr' => $ip, 'host' => 'musik.example', 'origin' => 'https://musik.example']);
$data = static fn ($r) => $r->data;
$cookieOf = static fn ($r) => preg_match('/^mr_session=([^;]*)/', $r->headers['Set-Cookie'] ?? '', $m) ? $m[1] : null;
$me = static fn (string $cookie) => $ctl->me(new Request('GET', '/auth/me', [], '', [], ['cookie' => 'mr_session=' . $cookie]))->data['user'];

echo "The one user\n";
$auth->ensure();
check('created once', $auth->createInitialUser('martin', 'start-pass', $now) === true);
check('...and never a second time (no other users, no overwrite)', $auth->createInitialUser('martin', 'other', $now) === false
    && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
$hash = (string) $pdo->query("SELECT password_hash FROM users WHERE username = 'martin'")->fetchColumn();
check('the password is stored only as a password_hash() hash', $hash !== 'start-pass' && password_verify('start-pass', $hash) && str_starts_with($hash, '$2y$'));
$internal = new InternalController(['import' => ['token' => str_repeat('t', 40)]], static fn () => $pdo, __DIR__ . '/..');
$init = $internal->initLogin(new Request('POST', '/internal/init-login', [], '{"password":"x"}', [], ['x-import-token' => str_repeat('t', 40)]));
check('the server\'s one-time endpoint changes nothing once a user exists', $init->status === 200 && $init->data['created'] === false);
check('...and does not exist without the import token', $internal->initLogin(new Request('POST', '/internal/init-login', [], '{"password":"x"}'))->status === 404);

echo "Login and logout\n";
$r = $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass']));
$cookie = $cookieOf($r);
check('right password: logged in, with a session cookie', $r->status === 200 && $cookie !== null && $data($r)['user']['username'] === 'martin');
check('...HttpOnly, Secure, SameSite=Strict, 30 days', str_contains($r->headers['Set-Cookie'], 'HttpOnly') && str_contains($r->headers['Set-Cookie'], 'Secure')
    && str_contains($r->headers['Set-Cookie'], 'SameSite=Strict') && str_contains($r->headers['Set-Cookie'], 'Max-Age=2592000'), $r->headers['Set-Cookie']);
check('the cookie holds no password, the database only a hash of its secret half', !str_contains((string) $cookie, 'start-pass')
    && !str_contains(json_encode($pdo->query('SELECT * FROM auth_sessions')->fetchAll()), explode(':', (string) $cookie)[1]));
check('the API knows who is logged in', ($me((string) $cookie)['username'] ?? null) === 'martin');
$now += 29 * 86400;
check('still logged in after 29 days', $me((string) $cookie) !== null);
$now += 2 * 86400;
check('...but not after 30 days', $me((string) $cookie) === null);
$now += 3600;
$r = $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass']));
$cookie = (string) $cookieOf($r);
$ctl->logout($req('/auth/logout', [], 'mr_session=' . $cookie));
check('logout ends the session on the server (the cookie is useless afterwards)', $me($cookie) === null);
check('a made-up cookie is not a session', $me(str_repeat('a', 24) . ':' . str_repeat('b', 64)) === null);
check('wrong password: a clear error', $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'nope']))->status === 401
    && $data($ctl->login($req('/auth/login', ['username' => 'nobody', 'password' => 'nope'])))['error'] === 'Wrong username or password');
check('another website cannot log in or out on your behalf (Origin check)', $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.0.0.1',
    ['origin' => 'https://evil.example']))->status === 403);

echo "At most 3 failed logins per 5 minutes\n";
$pdo->exec('DELETE FROM login_attempts');
$now += 3600;
foreach ([1, 2, 3] as $i) {
    $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => "wrong $i"], '', '10.0.0.9'));
}
$r = $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.0.0.9'));
check('after 3 failures even the right password is refused for now', $r->status === 429 && isset($r->headers['Retry-After']), json_encode([$r->status, $r->data]));
check('...with a message that says when to try again', str_contains((string) ($data($r)['error'] ?? ''), 'Try again in'));
check('another address is not blocked by them', $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.0.0.2'))->status === 200);
$now += 301;
check('the block ends by itself after 5 minutes (never a permanent lock)', $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.0.0.9'))->status === 200);
$pdo->exec('DELETE FROM login_attempts');
for ($i = 0; $i < Auth::GLOBAL_MAX_FAILED; $i++) {
    $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'x'], '', "10.1.0.$i"));
}
check('many addresses together are limited too', $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.2.0.1'))->status === 429);
$now += 301;
check('...also only for 5 minutes', $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.2.0.1'))->status === 200);
check('no raw addresses are stored', !str_contains(json_encode($pdo->query('SELECT * FROM login_attempts')->fetchAll()), '10.'));

echo "The client address cannot be chosen by the client\n";
$addr = static fn (string $remote, string $xff, array $trusted = []) => ClientAddress::of(new Request('GET', '/', [], '', [], ['remote-addr' => $remote, 'x-forwarded-for' => $xff]), $trusted);
check('by default only the address the web server sees counts (X-Forwarded-For ignored)', $addr('203.0.113.7', '1.2.3.4') === '203.0.113.7');
check('behind a trusted proxy: the right-most address that is not the proxy', $addr('10.0.0.5', '1.2.3.4, 198.51.100.9', ['10.0.0.0/8']) === '198.51.100.9');
check('...a spoofed left-most entry does not help a client', $addr('10.0.0.5', '9.9.9.9, 198.51.100.9', ['10.0.0.0/8']) === '198.51.100.9');
check('...garbage in the header falls back to the proxy address', $addr('10.0.0.5', 'nonsense', ['10.0.0.0/8']) === '10.0.0.5');
check('IPv6 ranges work too', ClientAddress::inAny('2a02:2350::1', ['2a02:2350::/32']) && !ClientAddress::inAny('2a03::1', ['2a02:2350::/32']));
$ctlProxy = new AuthController(static fn () => $auth, true, $clock, ['10.0.0.0/8']);
$pdo->exec('DELETE FROM login_attempts');
foreach ([1, 2, 3] as $i) {
    $ctlProxy->login($req('/auth/login', ['username' => 'martin', 'password' => 'x'], '', '10.0.0.5', ['x-forwarded-for' => '198.51.100.20']));
}
check('behind a trusted proxy each real client has its own limit', $ctlProxy->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.0.0.5',
    ['x-forwarded-for' => '198.51.100.21']))->status === 200);
$pdo->exec('DELETE FROM login_attempts');

echo "Changing the password\n";
$pdo->exec('DELETE FROM login_attempts');
$a = (string) $cookieOf($ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'])));
$b = (string) $cookieOf($ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.0.0.3')));
check('not without being logged in', $ctl->password($req('/auth/password', ['current' => 'start-pass', 'new' => 'new-password-1', 'confirm' => 'new-password-1']))->status === 401);
check('wrong current password: refused', $ctl->password($req('/auth/password', ['current' => 'nope', 'new' => 'new-password-1', 'confirm' => 'new-password-1'], "mr_session=$a"))->status === 400);
check('new passwords that differ: refused', $data($ctl->password($req('/auth/password', ['current' => 'start-pass', 'new' => 'new-password-1', 'confirm' => 'new-password-2'], "mr_session=$a")))['error']
    === 'The new passwords are not the same');
check('too short: refused', $ctl->password($req('/auth/password', ['current' => 'start-pass', 'new' => 'short', 'confirm' => 'short'], "mr_session=$a"))->status === 400);
$r = $ctl->password($req('/auth/password', ['current' => 'start-pass', 'new' => 'new-password-1', 'confirm' => 'new-password-1'], "mr_session=$a"));
$fresh = (string) $cookieOf($r);
check('right current password: changed', $r->status === 200 && $fresh !== '' && $fresh !== $a);
check('...stored as a new hash', password_verify('new-password-1', (string) $pdo->query("SELECT password_hash FROM users WHERE username = 'martin'")->fetchColumn()));
check('...other devices are logged out, this one gets a fresh session', $me($b) === null && $me($a) === null && $me($fresh) !== null);
check('...the old password no longer works, the new one does', $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'start-pass'], '', '10.0.0.4'))->status === 401
    && $ctl->login($req('/auth/login', ['username' => 'martin', 'password' => 'new-password-1'], '', '10.0.0.4'))->status === 200);
$pdo->exec('DELETE FROM login_attempts');
foreach ([1, 2, 3] as $i) {
    $ctl->password($req('/auth/password', ['current' => "guess $i", 'new' => 'abcdefgh1', 'confirm' => 'abcdefgh1'], "mr_session=$fresh", '10.0.0.5'));
}
check('guessing the current password counts as failed logins (same limit)', $ctl->password($req('/auth/password', ['current' => 'new-password-1', 'new' => 'abcdefgh1', 'confirm' => 'abcdefgh1'],
    "mr_session=$fresh", '10.0.0.5'))->status === 429);

echo "Settings needs a login\n";
check('the frontend hides Settings and the API refuses its changes without a session', $ctl->password($req('/auth/password', ['current' => 'x', 'new' => 'y', 'confirm' => 'y']))->status === 401);

$pdo->exec('DROP TABLE IF EXISTS users, auth_sessions, login_attempts');
echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
