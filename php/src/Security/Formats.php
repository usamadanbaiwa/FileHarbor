<?php

declare(strict_types=1);

namespace FileHarbor\Security;

use FileHarbor\Config\Config;
use FileHarbor\Http\ApiError;
use JsonException;
use stdClass;

final class Formats
{
    public const DEFAULT_TYPES = 'image/jpeg,image/png,image/webp,application/pdf,video/mp4';

    private const BUILT_IN = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
        'video/mp4' => ['mp4'],
        'image/gif' => ['gif'],
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'application/zip' => ['zip'],
        'application/gzip' => ['gz'],
    ];

    public static function normalize(string $type): string
    {
        $aliases = [
            'audio/x-wav' => 'audio/wav',
            'audio/vnd.wave' => 'audio/wav',
            'application/x-gzip' => 'application/gzip',
            'application/x-zip-compressed' => 'application/zip',
        ];

        if (isset($aliases[$type])) {
            return $aliases[$type];
        }

        return $type;
    }

    private static function validType(string $type): bool
    {
        $blocked = [
            'image/svg+xml',
            'application/octet-stream',
            'application/javascript',
            'application/x-httpd-php',
            'application/x-executable',
            'application/x-sharedlib',
            'application/x-dosexec',
            'application/x-msdownload',
            'application/wasm',
            'application/json',
            'application/xml',
            'application/xhtml+xml'
        ];

        return preg_match('#^(image|audio|video|application)/[a-z0-9][a-z0-9.+-]*$#D', $type) === 1 &&
            !in_array($type, $blocked, true);
    }

    public static function registry(string $input): array
    {
        try {
            $custom = json_decode($input, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new ApiError(500, 'CONFIG_ERROR', 'MIME_TYPE_EXTENSIONS must be a JSON object.');
        }

        ApiError::require($custom instanceof stdClass, 'CONFIG_ERROR', 'MIME_TYPE_EXTENSIONS must be a JSON object.', 500);
        ApiError::require(count((array) $custom) <= 64, 'CONFIG_ERROR', 'Too many custom file formats.', 500);

        $formats = self::BUILT_IN;

        foreach ((array) $custom as $type => $extensions) {
            ApiError::require(self::validType((string) $type), 'CONFIG_ERROR', 'Invalid or unsafe MIME type: ' . $type, 500);
            ApiError::require(
                is_array($extensions) && count($extensions) > 0 && count($extensions) <= 16,
                'CONFIG_ERROR',
                'Each MIME type needs an array of filename extensions.',
                500
            );

            foreach ($extensions as $extension) {
                ApiError::require(
                    is_string($extension) && preg_match('/^[a-z0-9]{1,12}$/D', $extension) === 1 &&
                    preg_match('/^(php[0-9]*|phtml|phar|html?|xhtml|svgz?|js|mjs|cjs|exe|dll|com|bat|cmd|ps1|sh|py|rb|pl|cgi|asp|aspx|jsp)$/D', $extension) !== 1,
                    'CONFIG_ERROR',
                    'Invalid or unsafe filename extension.',
                    500
                );
            }

            $formats[self::normalize($type)] = array_values(array_unique($extensions));
        }

        return $formats;
    }

    public static function allowed(string $input, array $formats): array
    {
        $allowed = [];

        foreach (explode(',', $input) as $type) {
            $type = self::normalize(trim($type));
            ApiError::require(
                self::validType($type) && isset($formats[$type]),
                'CONFIG_ERROR',
                'Allowed types must have a registered MIME_TYPE_EXTENSIONS entry: ' . $type,
                500
            );
            $allowed[] = $type;
        }

        return array_values(array_unique($allowed));
    }

    public static function matches(string $type, string $filename, Config $config): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($type, $config->allowed, true) && isset($config->formats[$type]) &&
            in_array($extension, $config->formats[$type], true);
    }

    public static function servesInline(string $type): bool
    {
        return in_array($type, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
            'video/mp4',
            'image/gif',
            'audio/mpeg',
            'audio/wav'
        ], true);
    }
}
