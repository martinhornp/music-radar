<?php

declare(strict_types=1);

namespace MusicRadar\Controllers;

use Closure;
use MusicRadar\Auth\Auth;
use MusicRadar\Http\ClientAddress;
use MusicRadar\Http\Request;
use MusicRadar\Http\Response;
use Throwable;

/**
 * Login for the one user (see Auth):
 *   POST /api/auth/login     {username, password} -> session cookie
 *   POST /api/auth/logout    ends this session
 *   GET  /api/auth/me        {user: {username} | null}
 *   POST /api/auth/password  {current, new, confirm} - logged in only
 * Changes need JSON from this website (Origin check) - with the SameSite
 * cookie that keeps other sites from acting on your behalf.
 */
final class AuthController
{
    private const MAX_BODY = 1024;

    /**
     * @param Closure(): Auth $auth connects lazily
     * @param Closure(): int|null $clock for tests
     * @param list<string> $trustedProxies see ClientAddress (empty: no client header is trusted)
     */
    public function __construct(
        private readonly Closure $auth,
        private readonly bool $secureCookie = true,
        private readonly ?Closure $clock = null,
        private readonly array $trustedProxies = [],
    ) {
    }

    public function me(Request $request): Response
    {
        return $this->handle(function () use ($request): Response {
            $user = $this->current($request);
            return Response::json(['user' => $user ? ['username' => $user['username']] : null]);
        });
    }

    public function login(Request $request): Response
    {
        return $this->changing($request, function (array $in) use ($request): Response {
            $r = ($this->auth)()->login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''), $this->client($request), $this->now());
            if (!$r['ok']) {
                return isset($r['retryAfter'])
                    ? new Response(['error' => $r['error']], 429, ['Retry-After' => (string) $r['retryAfter']])
                    : Response::error($r['error'], 401);
            }
            return new Response(['user' => $r['user']], 200, ['Set-Cookie' => Auth::cookieHeader($r['cookie'], $this->secureCookie)]);
        });
    }

    public function logout(Request $request): Response
    {
        return $this->changing($request, function () use ($request): Response {
            ($this->auth)()->logout($this->cookie($request));
            return new Response(['user' => null], 200, ['Set-Cookie' => Auth::cookieHeader(null, $this->secureCookie)]);
        });
    }

    public function password(Request $request): Response
    {
        return $this->changing($request, function (array $in) use ($request): Response {
            $user = $this->current($request);
            if ($user === null) {
                return Response::error('Log in first', 401);
            }
            $r = ($this->auth)()->changePassword($user, (string) ($in['current'] ?? ''), (string) ($in['new'] ?? ''), (string) ($in['confirm'] ?? ''),
                $this->client($request), $this->now());
            if (!$r['ok']) {
                return isset($r['retryAfter'])
                    ? new Response(['error' => $r['error']], 429, ['Retry-After' => (string) $r['retryAfter']])
                    : Response::error($r['error'], 400);
            }
            return new Response(['ok' => true], 200, ['Set-Cookie' => Auth::cookieHeader($r['cookie'], $this->secureCookie)]);
        });
    }

    /** The logged-in user, for other controllers (null = not logged in). */
    public function current(Request $request): ?array
    {
        $cookie = $this->cookie($request);
        if ($cookie === '') {
            return null;
        }
        $auth = ($this->auth)();
        $auth->ensure();
        return $auth->user($cookie, $this->now());
    }

    private function changing(Request $request, Closure $action): Response
    {
        if (!$this->sameOrigin($request)) {
            return Response::error('Forbidden', 403);
        }
        if (!str_starts_with(strtolower($request->header('content-type')), 'application/json') || strlen($request->body) > self::MAX_BODY) {
            return Response::error('Send a small JSON body', 415);
        }
        return $this->handle(fn () => $action($request->json()));
    }

    private function handle(Closure $action): Response
    {
        try {
            ($this->auth)()->ensure();
            return $action();
        } catch (Throwable $e) {
            error_log('auth: ' . $e::class . ': ' . $e->getMessage());
            return Response::error('Login is unavailable right now', 503);
        }
    }

    private function cookie(Request $request): string
    {
        foreach (explode(';', $request->header('cookie')) as $part) {
            [$k, $v] = array_map('trim', explode('=', $part, 2)) + [1 => ''];
            if ($k === Auth::COOKIE) {
                return $v;
            }
        }
        return '';
    }

    /** The client address for the failed-login limit (see ClientAddress). */
    private function client(Request $request): string
    {
        return ClientAddress::of($request, $this->trustedProxies);
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    private function sameOrigin(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin === '') {
            return true; // same-origin GET/HEAD or a non-browser client
        }
        $parts = parse_url($origin);
        $host = ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        return $host !== '' && strcasecmp($host, $request->header('host')) === 0;
    }
}
