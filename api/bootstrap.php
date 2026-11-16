<?php

declare(strict_types=1);

use MusicRadar\Http\Router;

// Minimal PSR-4 autoloader: MusicRadar\Foo\Bar -> src/Foo/Bar.php.
// one.com has no SSH, so we avoid depending on Composer on the server.
spl_autoload_register(static function (string $class): void {
    $prefix = 'MusicRadar\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = require __DIR__ . '/config/config.php';

error_reporting(E_ALL);
ini_set('display_errors', $config['debug'] ? '1' : '0');
date_default_timezone_set($config['timezone']);

$router = new Router();
(require __DIR__ . '/routes.php')($router, $config);

return (object) ['config' => $config, 'router' => $router];
