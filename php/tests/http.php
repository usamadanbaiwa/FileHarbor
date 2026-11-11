<?php

declare(strict_types=1);

require_once __DIR__ . '/HttpServer.php';

return [
    'HTTP configured formats upload and download with matching rules' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();
            $account = $server->account();
            $fixtures = ['gif' => 'image/gif', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
                'zip' => 'application/zip', 'gz' => 'application/gzip', 'tiff' => 'image/tiff'];

            foreach ($fixtures as $extension => $mime) {
                $name = 'sample.' . $extension;
                $file = __DIR__ . '/fixtures/' . $name;
                $upload = $server->upload($account, $name, $file);
                expectSame(201, $upload['status']);
                expectSame($mime, $upload['body']['data']['mime_type']);
                $response = $server->request('/' . $account['id'] . '/' . $name, ['raw' => true]);
                expectSame(200, $response['status']);
                expectSame($mime, $response['headers']['content-type']);
                expectSame(file_get_contents($file), $response['body']);

                if (in_array($extension, ['zip', 'gz', 'tiff'], true)) {
                    expectSame('attachment', $response['headers']['content-disposition']);
                } else {
                    expectSame(false, isset($response['headers']['content-disposition']));
                }
            }

            expectSame(415, $server->upload($account, 'wrong.zip', __DIR__ . '/fixtures/sample.gif')['status']);
            copy(__DIR__ . '/fixtures/sample.gif', $server->config->storage . '/' . $account['id'] . '/wrong.zip');
            expectSame(404, $server->request('/' . $account['id'] . '/wrong.zip')['status']);
        }, [
            'ALLOWED_MIME_TYPES' => 'image/gif,audio/mpeg,audio/wav,application/zip,application/gzip,image/tiff',
            'MIME_TYPE_EXTENSIONS' => '{"image/tiff":["tif","tiff"]}',
        ]);
    },

    'HTTP additional formats remain disabled by default' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();
            $account = $server->account();
            expectSame(415, $server->upload($account, 'sample.gif', __DIR__ . '/fixtures/sample.gif')['status']);
            copy(__DIR__ . '/fixtures/sample.gif', $server->config->storage . '/' . $account['id'] . '/sample.gif');
            expectSame(404, $server->request('/' . $account['id'] . '/sample.gif')['status']);
        });
    },

    'HTTP cache lifetime applies to downloads and ranges only' => function (): void {
        foreach ([0, 120] as $seconds) {
            withHttpServer(function (HttpTestServer $server) use ($seconds): void {
                $id = str_repeat('a', 22);
                mkdir($server->config->storage . '/' . $id);
                copy(__DIR__ . '/fixtures/image.png', $server->config->storage . '/' . $id . '/image.png');

                $response = $server->request('/' . $id . '/image.png', [
                    'raw' => true,
                    'headers' => ['Range' => 'bytes=0-7'],
                ]);

                expectSame(206, $response['status']);
                expectSame('public, max-age=' . $seconds . ', must-revalidate', $response['headers']['cache-control']);
                expectSame('no-store', $server->request('/api/health')['headers']['cache-control']);
            }, ['PUBLIC_CACHE_MAX_AGE' => $seconds]);
        }
    },

    'HTTP works with a renamed public_html document root' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            expectSame(200, $server->request('/panel/', ['raw' => true])['status']);
            expectSame(200, $server->request('/panel/style.css', ['raw' => true])['status']);
            expectSame(200, $server->request('/panel/script.js', ['raw' => true])['status']);
            expectSame(201, $server->setup()['status']);
            expectSame(200, $server->login()['status']);

            $account = $server->account();
            $image = __DIR__ . '/fixtures/image.png';

            expectSame(201, $server->upload($account, 'image.png', $image)['status']);
            expectSame(file_get_contents($image), $server->request('/' . $account['id'] . '/image.png', ['raw' => true])['body']);
            expectSame(404, $server->request('/src/App.php')['status']);
            expectSame(404, $server->request('/config/config.php')['status']);
        }, ['FILEHARBOR_DOCUMENT_ROOT' => 'public_html']);
    },

    'HTTP public files support GET HEAD caching and video ranges' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $server->setup();
            $server->login();
            $account = $server->account();
            $image = __DIR__ . '/fixtures/image.png';

            $server->request($account['route'] . '/directories', [
                'method' => 'POST',
                'headers' => $account['headers'],
                'data' => ['source' => 'assets'],
            ]);

            expectSame(201, $server->upload($account, 'image.png', $image, ['folder' => 'assets'])['status']);

            $url = '/' . $account['id'] . '/assets/image.png';
            $response = $server->request($url, ['raw' => true]);

            expectSame(200, $response['status']);
            expectSame(file_get_contents($image), $response['body']);
            expectSame('image/png', $response['headers']['content-type']);
            expectSame('public, max-age=86400, must-revalidate', $response['headers']['cache-control']);
            expectSame('nosniff', $response['headers']['x-content-type-options']);

            $head = $server->request($url, ['method' => 'HEAD', 'raw' => true]);

            expectSame(200, $head['status']);
            expectSame('', $head['body']);
            expectSame((string) filesize($image), $head['headers']['content-length']);

            $cached = $server->request($url, [
                'raw' => true,
                'headers' => ['If-None-Match' => $response['headers']['etag']],
            ]);

            expectSame(304, $cached['status']);
            expectSame('public, max-age=86400, must-revalidate', $cached['headers']['cache-control']);
            expectSame('', $cached['body']);

            $gzipTag = substr($response['headers']['etag'], 0, -1) . '-gzip"';
            $compressedCache = $server->request($url, [
                'raw' => true,
                'headers' => [
                    'If-None-Match' => $gzipTag,
                    'If-Modified-Since' => $response['headers']['last-modified'],
                ],
            ]);

            expectSame(304, $compressedCache['status']);
            expectSame('', $compressedCache['body']);

            $changed = $server->request($url, [
                'raw' => true,
                'headers' => [
                    'If-None-Match' => 'W/"different-file-gzip"',
                    'If-Modified-Since' => $response['headers']['last-modified'],
                ],
            ]);

            expectSame(200, $changed['status']);
            expectSame(file_get_contents($image), $changed['body']);

            $video = __DIR__ . '/fixtures/video-header.mp4';
            expectSame(201, $server->upload($account, 'clip.mp4', $video)['status']);
            $url = '/' . $account['id'] . '/clip.mp4';
            $bytes = file_get_contents($video);
            $range = $server->request($url, ['raw' => true, 'headers' => ['Range' => 'bytes=4-11']]);

            expectSame(206, $range['status']);
            expectSame('video/mp4', $range['headers']['content-type']);
            expectSame('bytes 4-11/' . strlen($bytes), $range['headers']['content-range']);
            expectSame(substr($bytes, 4, 8), $range['body']);

            $suffix = $server->request($url, ['raw' => true, 'headers' => ['Range' => 'bytes=-4']]);

            expectSame(206, $suffix['status']);
            expectSame(substr($bytes, -4), $suffix['body']);

            $invalid = $server->request($url, ['raw' => true, 'headers' => ['Range' => 'bytes=99999-']]);

            expectSame(416, $invalid['status']);
            expectSame('bytes */' . strlen($bytes), $invalid['headers']['content-range']);
        });
    },

    'HTTP public serving rejects unsafe paths directories and active content' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $id = str_repeat('a', 22);
            $root = $server->config->storage . '/' . $id;

            mkdir($root);
            file_put_contents($root . '/unsafe.png', '<html><script>alert(1)</script></html>');
            mkdir($root . '/empty');

            foreach (['unsafe.png', 'missing.png', 'empty', '%2e%2e%2fprivate.png', '%5cprivate.png'] as $source) {
                $response = $server->request('/' . $id . '/' . $source, ['raw' => true]);

                expectSame(true, in_array($response['status'], [400, 404], true));
            }

            expectSame(404, $server->request('/storage/' . $id . '/unsafe.png', ['raw' => true])['status']);
        });
    },

    'HTTP public serving can be disabled' => function (): void {
        withHttpServer(function (HttpTestServer $server): void {
            $id = str_repeat('a', 22);

            mkdir($server->config->storage . '/' . $id);
            copy(__DIR__ . '/fixtures/image.png', $server->config->storage . '/' . $id . '/image.png');

            expectSame(404, $server->request('/' . $id . '/image.png')['status']);
        }, ['SERVE_FILES' => 'false']);
    },

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
