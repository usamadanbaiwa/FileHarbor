<?php

declare(strict_types=1);

use FileHarbor\Config\Config;
use FileHarbor\Repositories\Registry;
use FileHarbor\Storage\Storage;

return [
    'unsafe paths and reserved names are rejected' => function (): void {
        $unsafe = ['../outside', '/absolute', 'a//b', 'CON.txt', 'a\\b', 'folder/.', 'trailing.'];

        foreach ($unsafe as $path) {
            expectError('INVALID_PATH', function () use ($path): void {
                Storage::segments($path);
            });
        }
    },

    'uploads cannot replace existing files' => function (): void {
        withFixture(function (Config $config, Registry $registry, Storage $storage): void {
            $account = $registry->createAccount('Blog', []);

            $storage->createAccountRoot($account['id']);
            $storage->createDirectory($account['id'], 'assets/blog');

            $temporary = $config->state . '/content';

            file_put_contents($temporary, 'original');
            $storage->put($account['id'], 'assets/blog/image.png', $temporary);

            expectError('FILE_EXISTS', function () use ($storage, $account, $temporary): void {
                $storage->put($account['id'], 'assets/blog/image.png', $temporary);
            });

            $destination = $storage->resolve($account['id'], 'assets/blog/image.png');

            expectSame('original', file_get_contents($destination));
        });
    },

    'directory listings are sorted and paginated' => function (): void {
        withFixture(function (Config $config, Registry $registry, Storage $storage): void {
            $account = $registry->createAccount('Blog', []);

            $storage->createAccountRoot($account['id']);
            $storage->createDirectory($account['id'], 'z-last');
            $storage->createDirectory($account['id'], 'a-first');

            $listing = $storage->listing($account['id'], '', 1, 1, true);

            expectSame(2, $listing['pagination']['total']);
            expectSame('a-first', $listing['items'][0]['file_name']);
        });
    },

    'non-empty directories and the account root cannot be deleted' => function (): void {
        withFixture(function (Config $config, Registry $registry, Storage $storage): void {
            $account = $registry->createAccount('Blog', []);

            $storage->createAccountRoot($account['id']);
            $storage->createDirectory($account['id'], 'assets/nested');

            expectError('CONFLICT', function () use ($storage, $account): void {
                $storage->remove($account['id'], 'assets', true);
            });

            expectError('INVALID_PATH', function () use ($storage, $account): void {
                $storage->remove($account['id'], '', true);
            });

            $storage->remove($account['id'], 'assets/nested', true);
            $storage->remove($account['id'], 'assets', true);
        });
    },

    'storage failures are sanitized in JSON responses' => function (): void {
        ob_start();

        FileHarbor\Http\Response::error(new RuntimeException('/private/secret/path'));

        $body = ob_get_clean();
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        expectSame('STORAGE_ERROR', $response['error']['code']);
        expectSame(false, str_contains($body, '/private/secret/path'));
    },
];
