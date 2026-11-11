<?php

declare(strict_types=1);

use FileHarbor\Config\Config;
use FileHarbor\Http\Request;

return [
    'public cache lifetime defaults to one day and accepts zero' => function (): void {
        $settings = [
            'FILEHARBOR_ENV' => 'development',
            'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
        ];

        expectSame(86400, Config::load($settings)->cacheMaxAge);
        expectSame(0, Config::load(array_merge($settings, ['PUBLIC_CACHE_MAX_AGE' => '0']))->cacheMaxAge);
        expectSame(31536000, Config::load(array_merge($settings, ['PUBLIC_CACHE_MAX_AGE' => '31536000']))->cacheMaxAge);

        foreach (['-1', '1.5', '31536001', '', false] as $value) {
            expectError('CONFIG_ERROR', function () use ($settings, $value): void {
                Config::load(array_merge($settings, ['PUBLIC_CACHE_MAX_AGE' => $value]));
            });
        }
    },

    'document root defaults to public and supports public_html' => function (): void {
        $settings = [
            'FILEHARBOR_ENV' => 'development',
            'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
        ];

        $base = str_replace('\\', '/', dirname(__DIR__));

        expectSame($base . '/public', Config::load($settings)->documentRoot);

        $settings['FILEHARBOR_DOCUMENT_ROOT'] = 'public_html';

        expectSame($base . '/public_html', Config::load($settings)->documentRoot);

        foreach (['../public', '/public', 'src', 'config', 'public/panel', 'public\\panel', ''] as $folder) {
            $settings['FILEHARBOR_DOCUMENT_ROOT'] = $folder;

            expectError('CONFIG_ERROR', function () use ($settings): void {
                Config::load($settings);
            });
        }
    },

    'renamed document roots cannot contain storage or private state' => function (): void {
        foreach (['STORAGE_PATH', 'FILEHARBOR_STATE_PATH'] as $setting) {
            expectError('CONFIG_ERROR', function () use ($setting): void {
                Config::load([
                    'FILEHARBOR_ENV' => 'development',
                    'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
                    'FILEHARBOR_DOCUMENT_ROOT' => 'public_html',
                    $setting => dirname(__DIR__) . '/public_html/data',
                ]);
            });
        }
    },

    'public file serving defaults on and requires an explicit boolean setting' => function (): void {
        $settings = [
            'FILEHARBOR_ENV' => 'development',
            'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
        ];

        expectSame(true, Config::load($settings)->serveFiles);
        expectSame(false, Config::load(array_merge($settings, ['SERVE_FILES' => 'false']))->serveFiles);

        expectError('CONFIG_ERROR', function () use ($settings): void {
            Config::load(array_merge($settings, ['SERVE_FILES' => 'no']));
        });
    },

    'private state cannot be placed in the public panel' => function (): void {
        expectError('CONFIG_ERROR', function (): void {
            Config::load([
                'FILEHARBOR_ENV' => 'development',
                'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
                'FILEHARBOR_STATE_PATH' => dirname(__DIR__) . '/public/panel/state',
            ]);
        });
    },

    'storage cannot contain application source' => function (): void {
        expectError('CONFIG_ERROR', function (): void {
            Config::load([
                'FILEHARBOR_ENV' => 'development',
                'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
                'STORAGE_PATH' => dirname(__DIR__) . '/src',
            ]);
        });
    },

    'missing setup token fails closed' => function (): void {
        expectError('CONFIG_ERROR', function (): void {
            Config::load(['FILEHARBOR_ENV' => 'development']);
        });
    },

    'production requires HTTPS' => function (): void {
        expectError('CONFIG_ERROR', function (): void {
            Config::load([
                'FILEHARBOR_URL' => 'http://example.com',
                'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
            ]);
        });
    },

    'private state cannot overlap public storage' => function (): void {
        expectError('CONFIG_ERROR', function (): void {
            Config::load([
                'STORAGE_PATH' => '/same',
                'FILEHARBOR_STATE_PATH' => '/same/private',
            ]);
        });
    },

    'invalid MIME configuration is rejected' => function (): void {
        expectError('CONFIG_ERROR', function (): void {
            Config::load([
                'FILEHARBOR_ENV' => 'development',
                'FILEHARBOR_SETUP_TOKEN' => str_repeat('a', 32),
                'ALLOWED_MIME_TYPES' => 'text/html',
            ]);
        });
    },

    'JSON arrays and malformed JSON are rejected' => function (): void {
        foreach (['[]', '{broken'] as $body) {
            expectError('INVALID_REQUEST', function () use ($body): void {
                new Request(['CONTENT_TYPE' => 'application/json'], [], [], [], $body);
            });
        }
    },

    'JSON request size is bounded' => function (): void {
        expectError('FILE_TOO_LARGE', function (): void {
            new Request(
                ['CONTENT_TYPE' => 'application/json'],
                [],
                [],
                [],
                str_repeat(' ', 16385)
            );
        });
    },
];
