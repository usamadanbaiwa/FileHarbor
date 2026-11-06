<?php

declare(strict_types=1);

namespace FileHarbor\Storage;

use FileHarbor\Support\Paths;
use FileHarbor\Http\ApiError;

use ErrorException;

final class Storage
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = $root;
    }

    public static function prepareRoot(string $root): void
    {
        self::createDirectoryIfMissing($root, true);

        $resolved = realpath($root);

        ApiError::require(
            $resolved !== false &&
            Paths::absolutePath($resolved) === Paths::absolutePath($root),
            'CONFIG_ERROR',
            'Storage must be a canonical directory without symlinks.',
            500
        );
    }

    public static function segments(mixed $source, bool $allowEmpty = false): array
    {
        ApiError::require(
            is_string($source) && strlen($source) <= 1024,
            'INVALID_PATH',
            'Invalid relative path.'
        );

        if ($allowEmpty && $source === '') {
            return [];
        }

        $parts = explode('/', $source);

        foreach ($parts as $part) {
            $validCharacters = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $part) === 1;
            $reserved = preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $part) === 1;

            ApiError::require(
                $validCharacters && !str_ends_with($part, '.') && !$reserved,
                'INVALID_PATH',
                'Use relative paths with safe filename characters.'
            );
        }

        return $parts;
    }

    public function resolve(
        string $accountId,
        mixed $source,
        bool $createParents = false,
        bool $allowEmpty = false
    ): string {
        $parts = array_merge([$accountId], self::segments($source, $allowEmpty));
        $current = $this->root;

        foreach ($parts as $index => $part) {
            $current .= '/' . $part;
            $isParent = $index < count($parts) - 1 || ($allowEmpty && $source === '');

            if ($createParents && $isParent) {
                self::createDirectoryIfMissing($current);
            }

            clearstatcache(true, $current);

            ApiError::require(!is_link($current), 'INVALID_PATH', 'Unsafe path.');

            if (file_exists($current)) {
                $resolved = realpath($current);

                ApiError::require(
                    $resolved !== false &&
                    Paths::absolutePath($resolved) === Paths::absolutePath($current) &&
                    (!$isParent || is_dir($current)),
                    'INVALID_PATH',
                    'Unsafe path.'
                );
            } elseif ($isParent) {
                throw new ApiError(404, 'FILE_NOT_FOUND', 'Directory not found.');
            }
        }

        return $current;
    }

    public function createAccountRoot(string $id): void
    {
        $directory = $this->root . '/' . $id;

        if (file_exists($directory) || is_link($directory)) {
            throw new ApiError(409, 'CONFLICT', 'The destination already exists.');
        }

        mkdir($directory, 0755);
    }

    public function put(string $id, string $source, string $temporary): void
    {
        $destination = $this->resolve($id, $source);

        if (file_exists($destination) || is_link($destination)) {
            throw new ApiError(409, 'FILE_EXISTS', 'The destination already exists.');
        }

        // A hard link commits the complete upload without overwriting another request's file.
        try {
            link($temporary, $destination);
        } catch (ErrorException $error) {
            clearstatcache(true, $destination);

            if (file_exists($destination) || is_link($destination)) {
                throw new ApiError(409, 'FILE_EXISTS', 'The destination already exists.');
            }

            throw $error;
        }
    }

    public function createDirectory(string $id, mixed $source): void
    {
        $directory = $this->resolve($id, $source, true);

        self::createDirectoryIfMissing($directory);

        ApiError::require(
            is_dir($directory) && !is_link($directory),
            'INVALID_PATH',
            'Unsafe directory.'
        );
    }

    public function listing(
        string $id,
        mixed $folder,
        int $page,
        int $limit,
        bool $directoriesOnly = false
    ): array {
        $directory = $this->resolve($id, $folder, false, true);

        ApiError::require(is_dir($directory), 'INVALID_PATH', 'Expected a directory.');

        $entries = [];

        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . '/' . $name;

            if (is_link($path)) {
                continue;
            }

            $resolved = realpath($path);

            if ($resolved === false || Paths::absolutePath($resolved) !== Paths::absolutePath($path)) {
                continue;
            }

            if (is_dir($path) || (!$directoriesOnly && is_file($path))) {
                $entries[] = $name;
            }
        }

        sort($entries, SORT_STRING);

        $selected = array_slice($entries, ($page - 1) * $limit, $limit);
        $items = [];

        foreach ($selected as $name) {
            $source = $name;

            if ($folder !== '') {
                $source = $folder . '/' . $name;
            }

            $path = $this->resolve($id, $source);
            $type = 'file';

            if (is_dir($path)) {
                $type = 'directory';
            }

            $items[] = [
                'file_name' => $name,
                'source' => $source,
                'type' => $type,
                'size' => filesize($path),
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => count($entries),
            ],
        ];
    }

    public function remove(string $id, mixed $source, bool $directory = false): void
    {
        $path = $this->resolve($id, $source);

        ApiError::require(file_exists($path), 'FILE_NOT_FOUND', 'File or directory not found.', 404);

        if ($directory) {
            ApiError::require(is_dir($path), 'INVALID_PATH', 'The path has the wrong type.');

            $entries = array_diff(scandir($path), ['.', '..']);

            ApiError::require(
                count($entries) === 0,
                'CONFLICT',
                'The destination exists or the directory is not empty.',
                409
            );

            rmdir($path);
        } else {
            ApiError::require(is_file($path), 'INVALID_PATH', 'The path has the wrong type.');

            unlink($path);
        }
    }

    private static function createDirectoryIfMissing(string $path, bool $recursive = false): void
    {
        if (is_dir($path)) {
            return;
        }

        try {
            mkdir($path, 0755, $recursive);
        } catch (ErrorException $error) {
            clearstatcache(true, $path);

            if (!is_dir($path)) {
                throw $error;
            }
        }
    }
}
