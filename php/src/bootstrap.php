<?php

declare(strict_types=1);

use FileHarbor\App;
use FileHarbor\Config\Config;
use FileHarbor\Http\Request;
use FileHarbor\Http\Response;

ini_set('display_errors', '0');

// Autoloader for classes 
spl_autoload_register(function (string $class): void {
    $prefix = 'FileHarbor\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $name = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $name) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

function runFileHarbor(): void
{
    set_error_handler(function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $config = Config::load();

        $app = new App($config);
        
        $app->handle(Request::fromGlobals());

    } catch (Throwable $error) {
        Response::error($error);
    } finally {
        restore_error_handler();
    }
}
