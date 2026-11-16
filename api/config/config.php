<?php

declare(strict_types=1);

/**
 * Default configuration. Environment-specific values (and secrets) go in
 * config.local.php, which is git-ignored and uploaded manually to the server.
 * Environment variables (used by Docker locally) override both.
 */

$config = [
    'app_name' => 'Music Radar',
    'debug' => false,
    'timezone' => 'Europe/Copenhagen',
    // URL prefix the API is served under.
    'base_path' => '/api',

    // Not used yet - reserved for the upcoming database integration.
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'password' => '',
    ],

    // Last.fm (listening history). The API key is a secret: set it in
    // config.local.php or the LASTFM_API_KEY env var - never here.
    'lastfm' => [
        'api_key' => '',
        'username' => 'MartinHornP',
    ],

    // Daily import (POST /api/internal/import, called by GitHub Actions).
    // The token is a secret: config.local.php (written by the deploy from the
    // IMPORT_TOKEN secret) or the IMPORT_TOKEN env var - never here. Without a
    // token the internal endpoints do not exist (404).
    'import' => [
        'token' => '',
        'budget_seconds' => 20,   // work per call; one.com stops PHP requests after a while
        'cache_dir' => null,      // MusicBrainz response cache; default: system temp dir
    ],

    // Login (one user, see MusicRadar\Auth\Auth). The session cookie is
    // Secure (HTTPS only); browsers accept it on http://localhost too.
    'auth' => [
        'secure_cookie' => true,
        // Proxies whose X-Forwarded-For may be trusted for the failed-login
        // limit (CIDR ranges). Empty: only the address the web server sees
        // counts - a client cannot pick its own address with a header.
        'trusted_proxies' => [],
    ],

    // Where the site's recommendations come from: 'database' (the active
    // recommendation run, falling back to data/feed.json if there is none or
    // the database is unreachable) or 'file' (data/feed.json only - emergency
    // switch, set with the GitHub variable FEED_SOURCE and a deploy).
    'feed' => [
        'source' => 'database',
    ],
];

$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $config = array_replace_recursive($config, require $localFile);
}

$env = static fn (string $key): ?string => ($v = getenv($key)) === false ? null : $v;

if (($debug = $env('APP_DEBUG')) !== null) {
    $config['debug'] = filter_var($debug, FILTER_VALIDATE_BOOL);
}
foreach (['host' => 'DB_HOST', 'port' => 'DB_PORT', 'name' => 'DB_NAME', 'user' => 'DB_USER', 'password' => 'DB_PASSWORD'] as $key => $var) {
    if (($value = $env($var)) !== null) {
        $config['db'][$key] = $key === 'port' ? (int) $value : $value;
    }
}
if (($value = $env('IMPORT_TOKEN')) !== null && $value !== '') {
    $config['import']['token'] = $value;
}
foreach (['api_key' => 'LASTFM_API_KEY', 'username' => 'LASTFM_USERNAME'] as $key => $var) {
    if (($value = $env($var)) !== null && $value !== '') {
        $config['lastfm'][$key] = $value;
    }
}

return $config;
