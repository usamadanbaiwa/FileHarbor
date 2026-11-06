<?php

declare(strict_types=1);

namespace FileHarbor\Support;

use FileHarbor\Http\ApiError;

final class Paths
{
    public static function absolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:\//', $path) !== 1) {
            $workingDirectory = getcwd();

            ApiError::require($workingDirectory !== false, 'CONFIG_ERROR', 'Cannot resolve paths.', 500);

            $path = str_replace('\\', '/', $workingDirectory) . '/' . $path;
        }

        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        $resolved = implode('/', $parts);

        if (str_starts_with($path, '/')) {
            $resolved = '/' . $resolved;
        }

        return $resolved;
    }

    public static function overlaps(string $first, string $second): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $first = strtolower($first);
            $second = strtolower($second);
        }

        return $first === $second || str_starts_with($second, rtrim($first, '/') . '/');
    }
}
