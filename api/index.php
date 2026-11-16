<?php

declare(strict_types=1);

/**
 * Front controller for the Music Radar API.
 *
 * Apache (.htaccess) rewrites every /api/* request to this file. Locally it
 * can also be used as the router script for PHP's built-in server:
 *   php -S localhost:8080 api/index.php
 */

use MusicRadar\Http\Request;
use MusicRadar\Http\Response;

// Don't advertise the PHP version (X-Powered-By: PHP/x.y.z).
header_remove('X-Powered-By');

$app = require __DIR__ . '/bootstrap.php';

try {
    $response = $app->router->dispatch(Request::fromGlobals($app->config['base_path']));
} catch (Throwable $e) {
    error_log((string) $e);
    $response = Response::error(
        'Internal server error',
        500,
        $app->config['debug'] ? ['exception' => $e->getMessage()] : []
    );
}

$response->send();
