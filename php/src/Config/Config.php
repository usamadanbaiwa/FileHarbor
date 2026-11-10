<?php

declare(strict_types=1);

namespace FileHarbor\Config;

use FileHarbor\Http\ApiError;
use FileHarbor\Support\Paths;

final class Config
{
    public string $documentRoot;
    public string $storage;
    public string $state;
    public string $origin;
    public string $setupToken;
    public bool $production;
    public bool $serveFiles;
    public int $maxBytes;
    public int $rateLimit;
    public int $rateWindow;
    public array $allowed;

    public static function load(?array $settings = null): self
    {
        ApiError::require(
            PHP_VERSION_ID >= 80300,
            'CONFIG_ERROR',
            'PHP 8.3 or newer is required.',
            500
        );

        if ($settings === null) {
            $settings = self::readSettings();
        }

        foreach (['pdo_sqlite', 'fileinfo'] as $extension) {
            ApiError::require(
                extension_loaded($extension),
                'CONFIG_ERROR',
                'Required PHP extension is unavailable: ' . $extension,
                500
            );
        }

        $config = new self();
        $serveFiles = self::text($settings, 'SERVE_FILES', 'true');

        ApiError::require(
            in_array($serveFiles, ['true', 'false'], true),
            'CONFIG_ERROR',
            'SERVE_FILES must be true or false.',
            500
        );

        $config->serveFiles = $serveFiles === 'true';
        $base = dirname(__DIR__, 2);

        $config->storage = Paths::absolutePath(self::text($settings, 'STORAGE_PATH', $base . '/storage'));
        $config->state = Paths::absolutePath(self::text($settings, 'FILEHARBOR_STATE_PATH', $base . '/var'));

        ApiError::require(
            !Paths::overlaps($config->storage, $config->state) &&
            !Paths::overlaps($config->state, $config->storage),
            'CONFIG_ERROR',
            'Storage and private state directories must be separate.',
            500
        );

        $config->documentRoot = self::documentRoot($settings);
        $public = $config->documentRoot;
        $source = Paths::absolutePath($base . '/src');

        foreach ([$config->storage, $config->state] as $directory) {
            ApiError::require(
                !Paths::overlaps($public, $directory) &&
                !Paths::overlaps($directory, $public) &&
                !Paths::overlaps($source, $directory) &&
                !Paths::overlaps($directory, $source),
                'CONFIG_ERROR',
                'Data directories must be outside the application source and public directories.',
                500
            );
        }

        $config->production = self::text($settings, 'FILEHARBOR_ENV', 'production') !== 'development';
        $config->origin = self::text($settings, 'FILEHARBOR_URL', 'http://127.0.0.1:7241');

        self::validateOrigin($config->origin, $config->production);

        $config->setupToken = self::text($settings, 'FILEHARBOR_SETUP_TOKEN', '');

        ApiError::require(
            strlen($config->setupToken) >= 32,
            'CONFIG_ERROR',
            'Set FILEHARBOR_SETUP_TOKEN to a random secret of at least 32 characters.',
            500
        );

        $config->maxBytes = self::integer($settings, 'MAX_FILE_SIZE_MB', 25, 1024) * 1024 * 1024;
        $config->rateLimit = self::integer($settings, 'API_RATE_LIMIT', 100, 100000);
        $config->rateWindow = self::integer($settings, 'API_RATE_WINDOW_SECONDS', 60, 3600);

        $types = self::text(
            $settings,
            'ALLOWED_MIME_TYPES',
            'image/jpeg,image/png,image/webp,application/pdf,video/mp4'
        );

        $config->allowed = array_map('trim', explode(',', $types));
        $supported = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4'];

        foreach ($config->allowed as $type) {
            ApiError::require(
                in_array($type, $supported, true),
                'CONFIG_ERROR',
                'Allowed types are JPEG, PNG, WebP, PDF, and MP4.',
                500
            );
        }


        return $config;
    }

    public static function documentRoot(?array $settings = null): string
    {
        if ($settings === null) {
            $settings = self::readSettings();
        }

        $folder = self::text($settings, 'FILEHARBOR_DOCUMENT_ROOT', 'public');
        $reserved = ['src', 'config', 'vendor', 'var', 'storage', 'tests', 'tools', 'docs', 'examples'];

        ApiError::require(
            preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $folder) === 1 &&
            !in_array(strtolower($folder), $reserved, true),
            'CONFIG_ERROR',
            'FILEHARBOR_DOCUMENT_ROOT must name a public folder such as public or public_html.',
            500
        );

        return Paths::absolutePath(dirname(__DIR__, 2) . '/' . $folder);
    }

    private static function readSettings(): array
    {
        $settings = [];

        $filename = dirname(__DIR__, 2) . '/config/config.php';

        if (is_file($filename)) {
            $settings = require $filename;

            ApiError::require(
                is_array($settings),
                'CONFIG_ERROR',
                'Configuration must return an array.',
                500
            );
        }

        $names = [
            'FILEHARBOR_DOCUMENT_ROOT',
            'FILEHARBOR_ENV',
            'FILEHARBOR_URL',
            'FILEHARBOR_SETUP_TOKEN',
            'STORAGE_PATH',
            'FILEHARBOR_STATE_PATH',
            'SERVE_FILES',
            'MAX_FILE_SIZE_MB',
            'ALLOWED_MIME_TYPES',
            'API_RATE_LIMIT',
            'API_RATE_WINDOW_SECONDS',
        ];

        foreach ($names as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $settings[$name] = $value;
            }
        }

        return $settings;
    }

    private static function text(array $settings, string $name, string $default): string
    {
        if (!array_key_exists($name, $settings)) {
            return $default;
        }

        ApiError::require(is_string($settings[$name]), 'CONFIG_ERROR', 'Invalid ' . $name . '.', 500);

        return $settings[$name];
    }

    private static function integer(array $settings, string $name, int $default, int $maximum): int
    {
        $value = $default;

        if (array_key_exists($name, $settings)) {
            $input = $settings[$name];

            ApiError::require(
                (is_int($input) || is_string($input)) &&
                filter_var($input, FILTER_VALIDATE_INT) !== false,
                'CONFIG_ERROR',
                'Invalid ' . $name . '.',
                500
            );

            $value = (int) $input;
        }

        ApiError::require(
            $value > 0 && $value <= $maximum,
            'CONFIG_ERROR',
            'Invalid ' . $name . '.',
            500
        );

        return $value;
    }

    private static function validateOrigin(string $origin, bool $production): void
    {
        $parts = parse_url($origin);

        ApiError::require(
            is_array($parts) &&
            isset($parts['scheme'], $parts['host']) &&
            in_array($parts['scheme'], ['http', 'https'], true) &&
            !isset($parts['user']) &&
            !isset($parts['pass']) &&
            !isset($parts['path']) &&
            !isset($parts['query']) &&
            !isset($parts['fragment']),
            'CONFIG_ERROR',
            'FILEHARBOR_URL must be an origin without a trailing slash.',
            500
        );

        ApiError::require(
            !$production || $parts['scheme'] === 'https',
            'CONFIG_ERROR',
            'Production requires an HTTPS FILEHARBOR_URL.',
            500
        );
    }
}
