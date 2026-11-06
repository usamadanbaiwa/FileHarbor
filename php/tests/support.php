<?php

declare(strict_types=1);

use FileHarbor\Http\ApiError;
use FileHarbor\Config\Config;
use FileHarbor\Support\Paths;
use FileHarbor\Security\Credentials;
use FileHarbor\Repositories\Registry;
use FileHarbor\Storage\Storage;

function expectSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function expectError(string $code, callable $operation): void
{
    try {
        $operation();
    } catch (ApiError $error) {
        expectSame($code, $error->errorCode);
        return;
    }

    throw new RuntimeException('Expected error ' . $code . '.');
}

function withFixture(callable $operation): void
{
    $base = dirname(__DIR__) . '/var/tests';

    if (!is_dir($base)) {
        mkdir($base, 0700, true);
    }

    $root = $base . '/test-' . Credentials::secret();

    mkdir($root, 0700);

    try {
        $config = Config::load([
            'FILEHARBOR_ENV' => 'development',
            'FILEHARBOR_URL' => 'http://127.0.0.1:8080',
            'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
            'STORAGE_PATH' => $root . '/storage',
            'FILEHARBOR_STATE_PATH' => $root . '/state',
        ]);

        Storage::prepareRoot($config->storage);
        Storage::prepareRoot($config->state);

        $registry = new Registry($config->state);
        $storage = new Storage($config->storage);

        try {
            $operation($config, $registry, $storage);
        } finally {
            unset($registry, $storage);
        }
    } finally {
        removeFixture($root, $base);
    }
}

function removeFixture(string $path, string $base): void
{
    $normalizedPath = Paths::absolutePath($path);
    $normalizedBase = Paths::absolutePath($base);

    if (!str_starts_with($normalizedPath, $normalizedBase . '/test-')) {
        throw new RuntimeException('Refusing to clean a path outside the test directory.');
    }

    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }

    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        removeFixture($path . '/' . $name, $base);
    }

    rmdir($path);
}
