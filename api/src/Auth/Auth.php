<?php

declare(strict_types=1);

namespace MusicRadar\Auth;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Login for the one user of Music Radar (martin) - no sign-up, no user
 * administration on the website. The user is created once, outside the
 * website (createInitialUser(): api/bin/create-user.php locally, the
 * protected internal endpoint from a GitHub workflow on the server).
 *
 * Sessions live on the server: the browser gets a random token in an
 * HttpOnly, Secure, SameSite=Strict cookie ("selector:validator"); the
 * database keeps only a SHA-256 hash of the validator. One mechanism for
 * both the session and "stay logged in": it is valid for 30 days from the
 * login (or until logout / a password change). The password is never
 * stored in the browser; passwords are stored with password_hash().
 *
 * Failed logins: at most MAX_FAILED per client address per WINDOW seconds
 * (and GLOBAL_MAX_FAILED from everyone together, against attacks spread over
 * many addresses). A block always ends by itself after the window - nobody
 * can lock the account permanently.
 */
final class Auth
{
    public const COOKIE = 'mr_session';
    public const SESSION_DAYS = 30;
    public const MAX_FAILED = 3;
    public const GLOBAL_MAX_FAILED = 30;
    public const WINDOW = 300;
    public const MIN_PASSWORD = 8;

    private const TABLES = [
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(64) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          created_at DATETIME NOT NULL,
          password_changed_at DATETIME NOT NULL,
          UNIQUE KEY users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS auth_sessions (
          selector CHAR(24) NOT NULL PRIMARY KEY,
          validator_hash CHAR(64) NOT NULL COMMENT 'SHA-256 of the secret half of the cookie',
          user_id INT UNSIGNED NOT NULL,
          created_at DATETIME NOT NULL,
          expires_at DATETIME NOT NULL,
          last_seen_at DATETIME NOT NULL,
          KEY auth_sessions_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
        <<<'SQL'
        CREATE TABLE IF NOT EXISTS login_attempts (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          client CHAR(64) NOT NULL COMMENT 'SHA-256 of the client address (no raw IPs stored)',
          attempted_at DATETIME NOT NULL,
          KEY login_attempts_time (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL,
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Creates the tables on first use (only CREATE TABLE IF NOT EXISTS). */
    public function ensure(): void
    {
        try {
            $this->db->query('SELECT 1 FROM login_attempts LIMIT 0');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1146) { // 1146 = table doesn't exist
                throw $e;
            }
            foreach (self::TABLES as $sql) {
                $this->db->exec($sql);
            }
        }
    }

    /** The one-time creation of the user - only while no user exists. */
    public function createInitialUser(string $username, string $password, int $now): bool
    {
        if ($password === '' || $username === '') {
            throw new InvalidArgumentException('Username and password are required');
        }
        if ((int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            return false;
        }
        $at = date('Y-m-d H:i:s', $now);
        $this->db->prepare('INSERT INTO users (username, password_hash, created_at, password_changed_at) VALUES (?, ?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $at, $at]);
        return true;
    }

    /**
     * @return array{ok: true, cookie: string, user: array{username: string}}|array{ok: false, error: string, retryAfter?: int}
     */
    public function login(string $username, string $password, string $client, int $now): array
    {
        $since = date('Y-m-d H:i:s', $now - self::WINDOW);
        $this->db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([$since]);
        $clientKey = hash('sha256', $client);
        if (($wait = $this->blockedFor($clientKey, $now)) > 0) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in ' . (int) ceil($wait / 60) . ' minute(s).', 'retryAfter' => $wait];
        }
        $s = $this->db->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
        $s->execute([$username]);
        $user = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        // Same work whether or not the user exists.
        static $dummy = null;
        $ok = password_verify($password, $user['password_hash'] ?? ($dummy ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)));
        if (!$ok || $user === null) {
            $this->db->prepare('INSERT INTO login_attempts (client, attempted_at) VALUES (?, ?)')->execute([$clientKey, date('Y-m-d H:i:s', $now)]);
            return ['ok' => false, 'error' => 'Wrong username or password'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        $this->db->prepare('DELETE FROM login_attempts WHERE client = ?')->execute([$clientKey]);
        return ['ok' => true, 'cookie' => $this->newSession((int) $user['id'], $now), 'user' => ['username' => $user['username']]];
    }

    /** Seconds until this client may try again (0 = now). */
    private function blockedFor(string $clientKey, int $now): int
    {
        $since = date('Y-m-d H:i:s', $now - self::WINDOW);
        foreach ([[$clientKey, self::MAX_FAILED], [null, self::GLOBAL_MAX_FAILED]] as [$key, $max]) {
            $s = $this->db->prepare('SELECT attempted_at FROM login_attempts WHERE attempted_at >= ?' . ($key !== null ? ' AND client = ?' : '') . ' ORDER BY attempted_at DESC LIMIT ' . (int) $max);
            $s->execute($key !== null ? [$since, $key] : [$since]);
            $times = $s->fetchAll(PDO::FETCH_COLUMN);
            if (count($times) >= $max) {
                return max(1, strtotime((string) end($times)) + self::WINDOW - $now);
            }
        }
        return 0;
    }

    /** @return array{id: int, username: string, selector: string}|null the logged-in user for this cookie */
    public function user(string $cookie, int $now): ?array
    {
        if (!preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $m)) {
            return null;
        }
        $s = $this->db->prepare('SELECT s.selector, s.validator_hash, s.expires_at, s.last_seen_at, u.id, u.username FROM auth_sessions s JOIN users u ON u.id = s.user_id WHERE s.selector = ?');
        $s->execute([$m[1]]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $m[2])) || strtotime($row['expires_at']) <= $now) {
            return null;
        }
        if (strtotime($row['last_seen_at']) < $now - 3600) {
            $this->db->prepare('UPDATE auth_sessions SET last_seen_at = ? WHERE selector = ?')->execute([date('Y-m-d H:i:s', $now), $m[1]]);
        }
        return ['id' => (int) $row['id'], 'username' => $row['username'], 'selector' => $row['selector']];
    }

    public function logout(string $cookie): void
    {
        if (preg_match('/^([0-9a-f]{24}):/', $cookie, $m)) {
            $this->db->prepare('DELETE FROM auth_sessions WHERE selector = ?')->execute([$m[1]]);
        }
    }

    /**
     * Checks the current password (a wrong one counts as a failed login),
     * stores the new one as a hash, ends every other session (other devices
     * must log in again) and gives this browser a fresh session.
     *
     * @return array{ok: true, cookie: string}|array{ok: false, error: string, retryAfter?: int}
     */
    public function changePassword(array $user, string $current, string $new, string $confirm, string $client, int $now): array
    {
        $clientKey = hash('sha256', $client);
        if (($wait = $this->blockedFor($clientKey, $now)) > 0) {
            return ['ok' => false, 'error' => 'Too many failed attempts. Try again in ' . (int) ceil($wait / 60) . ' minute(s).', 'retryAfter' => $wait];
        }
        $s = $this->db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $s->execute([$user['id']]);
        if (!password_verify($current, (string) $s->fetchColumn())) {
            $this->db->prepare('INSERT INTO login_attempts (client, attempted_at) VALUES (?, ?)')->execute([$clientKey, date('Y-m-d H:i:s', $now)]);
            return ['ok' => false, 'error' => 'Your current password is not correct'];
        }
        if ($new !== $confirm) {
            return ['ok' => false, 'error' => 'The new passwords are not the same'];
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            return ['ok' => false, 'error' => 'The new password must be at least ' . self::MIN_PASSWORD . ' characters'];
        }
        if ($new === $current) {
            return ['ok' => false, 'error' => 'The new password is the same as the current one'];
        }
        $at = date('Y-m-d H:i:s', $now);
        $this->db->prepare('UPDATE users SET password_hash = ?, password_changed_at = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $at, $user['id']]);
        $this->db->prepare('DELETE FROM auth_sessions WHERE user_id = ?')->execute([$user['id']]);
        return ['ok' => true, 'cookie' => $this->newSession($user['id'], $now)];
    }

    private function newSession(int $userId, int $now): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $this->db->prepare('DELETE FROM auth_sessions WHERE expires_at < ?')->execute([date('Y-m-d H:i:s', $now)]);
        $this->db->prepare('INSERT INTO auth_sessions (selector, validator_hash, user_id, created_at, expires_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$selector, hash('sha256', $validator), $userId, date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + self::SESSION_DAYS * 86400), date('Y-m-d H:i:s', $now)]);
        return "$selector:$validator";
    }

    /** Set-Cookie value: the session token, or (null) removing it. */
    public static function cookieHeader(?string $value, bool $secure = true): string
    {
        $attrs = 'Path=/; HttpOnly; SameSite=Strict' . ($secure ? '; Secure' : '');
        return $value === null
            ? self::COOKIE . "=; Max-Age=0; $attrs"
            : self::COOKIE . "=$value; Max-Age=" . (self::SESSION_DAYS * 86400) . "; $attrs";
    }
}
