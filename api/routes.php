<?php

declare(strict_types=1);

use MusicRadar\Auth\Auth;
use MusicRadar\Controllers\AuthController;
use MusicRadar\Controllers\FeedbackController;
use MusicRadar\Controllers\HealthController;
use MusicRadar\Controllers\InternalController;
use MusicRadar\Controllers\RecommendationsController;
use MusicRadar\Controllers\SearchController;
use MusicRadar\Controllers\TopAlbumsController;
use MusicRadar\Database\Database;
use MusicRadar\Feed\CurrentFeed;
use MusicRadar\Feed\FeedFile;
use MusicRadar\Feed\RecommendationStore;
use MusicRadar\Feedback\FeedbackRepository;
use MusicRadar\Feedback\Schema;
use MusicRadar\Http\Request;
use MusicRadar\Http\Router;
use MusicRadar\Links\TidalLinkStore;
use MusicRadar\Search\ReleaseSearch;
use MusicRadar\TopAlbums\TopAlbums;

return static function (Router $router, array $config): void {
    // One database connection per request, opened only when a route needs it.
    $pdo = null;
    $db = static function () use ($config, &$pdo): PDO {
        if (!Database::isConfigured($config['db'])) {
            throw new RuntimeException('database not configured');
        }
        return $pdo ??= Database::connect($config['db']);
    };
    $links = static function (array $items) use ($db): array {
        try {
            return (new TidalLinkStore($db()))->attach($items);
        } catch (Throwable) {
            // Optional enrichment must never break feed, search or feedback.
            foreach ($items as &$item) {
                $item['tidalAlbumId'] = null;
            }
            return $items;
        }
    };

    // Recommendations from the database (active run); feed.json is the fallback.
    // Emergency switch: feed.source = 'file' (GitHub variable FEED_SOURCE) serves
    // feed.json only, without touching the database.
    $feed = new CurrentFeed(
        ($config['feed']['source'] ?? 'database') === 'file'
            ? static fn () => throw new RuntimeException('feed source switched to file')
            : static fn () => new RecommendationStore($db()),
        new FeedFile(__DIR__ . '/data/feed.json'),
        static function (string $id) use ($db): ?array {
            $search = new ReleaseSearch($db());
            $row = $search->find($id);
            // Not a stored new release: one of your top albums (any age) can be rated too.
            return $row ? $search->toFeedEntry($row) : (new TopAlbums($db()))->entry($id);
        },
    );
    // Login for the one user (no sign-up on the website).
    $auth = new AuthController(static fn () => new Auth($db()), (bool) ($config['auth']['secure_cookie'] ?? true), null,
        (array) ($config['auth']['trusted_proxies'] ?? []));
    // Your ratings, Not interested, Save for later: anyone may read them, only you (logged in) may change them.
    $feedback = new FeedbackController(static function () use ($db): FeedbackRepository {
        Schema::ensure($db());
        return new FeedbackRepository($db());
    }, $feed, static fn (Request $request): bool => $auth->current($request) !== null, $links);
    // Daily import + migration: only with the secret token (404 without one configured).
    $internal = new InternalController($config, $db, __DIR__);

    $router->get('/health', [new HealthController(), 'show']);
    $router->get('/recommendations', [new RecommendationsController($feed, $links), 'index']);
    $router->get('/search', [new SearchController($db, $links), 'index']);
    $router->get('/top-albums', [new TopAlbumsController($db), 'index']);
    $router->get('/feedback', [$feedback, 'index']);
    $router->post('/feedback/{id}', [$feedback, 'update']);
    $router->get('/auth/me', [$auth, 'me']);
    $router->post('/auth/login', [$auth, 'login']);
    $router->post('/auth/logout', [$auth, 'logout']);
    $router->post('/auth/password', [$auth, 'password']);
    $router->post('/internal/import', [$internal, 'import']);
    $router->get('/internal/status', [$internal, 'status']);
    $router->get('/internal/diagnostics', [$internal, 'diagnostics']);
    $router->get('/internal/profile', [$internal, 'profile']);
    $router->post('/internal/profile', [$internal, 'saveProfile']);
    $router->post('/internal/library', [$internal, 'saveLibrary']);
    $router->post('/internal/migrate', [$internal, 'migrate']);
    $router->post('/internal/init-login', [$internal, 'initLogin']);
    $router->post('/internal/top-albums', [$internal, 'saveTopAlbums']);
};
