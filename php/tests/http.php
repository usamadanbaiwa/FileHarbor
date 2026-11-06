<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpServer.php';

return [
    'HTTP panel and its assets are available' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $page = $server->request('/panel/', ['raw' => true]);

            expectSame(200, $page['status']);
            expectSame(true, str_contains($page['body'], 'setup-form'));

            foreach (['script.js', 'style.css'] as $asset) {
                $response = $server->request('/panel/' . $asset, ['raw' => true]);

                expectSame(200, $response['status']);
            }
        });
    },

    'HTTP health returns the standard envelope' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $response = $server->request('/api/health');

            expectSame(200, $response['status']);
            expectSame(['success' => true, 'data' => ['status' => 'ok']], $response['body']);
        });
    },

    'HTTP setup locks and administrator requests require CSRF and origin' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            expectSame(201, $server->setup()['status']);
            expectSame(409, $server->setup()['status']);
            expectSame(401, $server->request('/panel-api/accounts')['status']);
            expectSame(200, $server->login()['status']);

            $csrf = $server->request('/panel-api/accounts', [
                'method' => 'POST',
                'admin' => true,
                'headers' => ['X-CSRF-Token' => 'wrong'],
                'data' => [],
            ]);

            $origin = $server->request('/panel-api/accounts', [
                'method' => 'POST',
                'admin' => true,
                'headers' => ['Origin' => 'https://attacker.example'],
                'data' => [],
            ]);

            expectSame(403, $csrf['status']);
            expectSame(403, $origin['status']);
        });
    },

    'HTTP keys enforce scope and account ownership' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();

            $owner = $server->account();
            $reader = $server->account(['files:list']);
            $image = __DIR__ . '/fixtures/image.png';

            expectSame(401, $server->request($owner['route'] . '/files')['status']);
            expectSame(403, $server->upload($reader, 'image.png', $image)['status']);

            $otherAccount = $server->request($owner['route'] . '/files', ['headers' => $reader['headers']]);

            expectSame(401, $otherAccount['status']);
        });
    },

    'HTTP uploads preserve bytes and support listing and deletion' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();

            $account = $server->account();

            $directory = $server->request($account['route'] . '/directories', [
                'method' => 'POST',
                'headers' => $account['headers'],
                'data' => ['source' => 'assets'],
            ]);

            expectSame(201, $directory['status']);

            $image = __DIR__ . '/fixtures/image.png';
            $uploaded = $server->upload($account, 'photo.png', $image, ['folder' => 'assets']);

            expectSame(201, $uploaded['status']);
            expectSame('assets/photo.png', $uploaded['body']['data']['source']);
            expectSame(file_get_contents($image), file_get_contents($server->config->storage . '/' . $account['id'] . '/assets/photo.png'));
            expectSame(409, $server->upload($account, 'photo.png', $image, ['folder' => 'assets'])['status']);

            $listed = $server->request($account['route'] . '/files?folder=assets&limit=1', [
                'headers' => $account['headers'],
            ]);

            expectSame(1, $listed['body']['data']['pagination']['total']);
            expectSame('photo.png', $listed['body']['data']['items'][0]['file_name']);

            $deleted = $server->request($account['route'] . '/files', [
                'method' => 'DELETE',
                'headers' => $account['headers'],
                'data' => ['source' => 'assets/photo.png'],
            ]);

            expectSame(200, $deleted['status']);
        });
    },

    'HTTP rejects unsafe paths and content disguised as a video' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();

            $account = $server->account();

            foreach (['../outside', '/absolute', 'a\\b', "folder\n", 'CON.txt'] as $source) {
                $response = $server->request($account['route'] . '/files', [
                    'method' => 'DELETE',
                    'headers' => $account['headers'],
                    'data' => ['source' => $source],
                ]);

                expectSame(400, $response['status']);
            }

            $fake = $server->upload($account, 'fake.mp4', __DIR__ . '/fixtures/image.png');

            expectSame(415, $fake['status']);
        });
    },

    'HTTP accepts MP4 up to 25 MiB and rejects one byte above the limit' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();

            $account = $server->account();
            $filename = dirname($server->config->state) . '/sized.mp4';
            $stream = fopen($filename, 'w+b');

            fwrite($stream, file_get_contents(__DIR__ . '/fixtures/video-header.mp4'));
            ftruncate($stream, 25 * 1024 * 1024);
            fclose($stream);

            $accepted = $server->upload($account, 'clip.mp4', $filename);

            expectSame(201, $accepted['status']);
            expectSame('video/mp4', $accepted['body']['data']['mime_type']);
            expectSame(25 * 1024 * 1024, $accepted['body']['data']['size']);

            $stream = fopen($filename, 'r+b');
            ftruncate($stream, 25 * 1024 * 1024 + 1);
            fclose($stream);

            $rejected = $server->upload($account, 'too-large.mp4', $filename);

            expectSame(413, $rejected['status']);
        });
    },

    'HTTP rotation and account removal revoke access but retain files' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();

            $account = $server->account();
            $server->upload($account, 'image.png', __DIR__ . '/fixtures/image.png');

            $rotated = $server->request('/panel-api/accounts/' . $account['id'] . '/rotate', [
                'method' => 'POST',
                'admin' => true,
                'data' => [],
            ]);

            expectSame(200, $rotated['status']);
            expectSame(401, $server->request($account['route'] . '/files', ['headers' => $account['headers']])['status']);

            $newHeaders = ['Authorization' => 'Bearer ' . $rotated['body']['data']['api_key']];

            expectSame(200, $server->request($account['route'] . '/files', ['headers' => $newHeaders])['status']);

            $disabled = $server->request('/panel-api/accounts/' . $account['id'], [
                'method' => 'PATCH',
                'admin' => true,
                'data' => ['name' => 'Disabled', 'permissions' => ['files:list'], 'enabled' => false],
            ]);

            expectSame(200, $disabled['status']);
            expectSame(401, $server->request($account['route'] . '/files', ['headers' => $newHeaders])['status']);

            $removed = $server->request('/panel-api/accounts/' . $account['id'], [
                'method' => 'DELETE',
                'admin' => true,
            ]);

            expectSame(200, $removed['status']);
            expectSame(true, is_file($server->config->storage . '/' . $account['id'] . '/image.png'));
        });
    },

    'HTTP logout invalidates the current session' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();

            $response = $server->request('/panel-api/logout', [
                'method' => 'POST',
                'admin' => true,
                'data' => [],
            ]);

            expectSame(200, $response['status']);
            expectSame(401, $server->request('/panel-api/accounts', ['admin' => true])['status']);
        });
    },

    'HTTP credential changes invalidate existing sessions and old passwords' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();

            $changed = $server->request('/panel-api/credentials', [
                'method' => 'POST',
                'admin' => true,
                'data' => [
                    'current_password' => 'test-only-password-123',
                    'username' => 'newadmin',
                    'password' => 'new-test-only-password-123',
                ],
            ]);

            expectSame(200, $changed['status']);
            expectSame(401, $server->request('/panel-api/accounts', ['admin' => true])['status']);
            expectSame(401, $server->login()['status']);
        });
    },
];
