<?php

declare(strict_types=1);

use FileHarbor\Config\Config;
use FileHarbor\Http\Request;

return [
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
