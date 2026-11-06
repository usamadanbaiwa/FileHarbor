<?php

declare(strict_types=1);

use FileHarbor\Config\Config;
use FileHarbor\Security\Credentials;
use FileHarbor\Security\RateLimit;
use FileHarbor\Repositories\Registry;
use FileHarbor\Http\Request;
use FileHarbor\Security\Sessions;

return [
    'rate limits survive another PHP request' => function (): void {
        withFixture(function (Config $config, Registry $registry): void {
            $config->rateLimit = 2;

            $request = new Request(['REMOTE_ADDR' => '127.0.0.1'], [], [], [], '');
            $limiter = new RateLimit($registry, $config);

            $limiter->check($request, 'api');
            $limiter->check($request, 'api');

            $nextRegistry = new Registry($config->state);
            $nextLimiter = new RateLimit($nextRegistry, $config);

            expectError('RATE_LIMIT_EXCEEDED', function () use ($nextLimiter, $request): void {
                $nextLimiter->check($request, 'api');
            });

            $registry->query('UPDATE rate_limits SET expires = ?', [time() - 1]);
            $nextLimiter->check($request, 'api');
        });
    },

    'expired sessions cannot authenticate' => function (): void {
        withFixture(function (Config $config, Registry $registry): void {
            $token = Credentials::secret();

            $registry->query(
                'INSERT INTO sessions (id, csrf, expires) VALUES (?, ?, ?)',
                [Credentials::digest($token), Credentials::secret(), time() - 1]
            );

            $request = new Request([
                'HTTP_COOKIE' => 'fh_session=' . $token,
            ], [], [], [], '');

            $sessions = new Sessions($registry, $config);

            expectError('UNAUTHORIZED', function () use ($sessions, $request): void {
                $sessions->authenticate($request);
            });
        });
    },

    'mutating panel requests require both CSRF and origin' => function (): void {
        withFixture(function (Config $config, Registry $registry): void {
            $token = Credentials::secret();
            $csrf = Credentials::secret();

            $registry->query(
                'INSERT INTO sessions (id, csrf, expires) VALUES (?, ?, ?)',
                [Credentials::digest($token), $csrf, time() + 3600]
            );

            $request = new Request([
                'REQUEST_METHOD' => 'POST',
                'HTTP_COOKIE' => 'fh_session=' . $token,
                'HTTP_X_CSRF_TOKEN' => $csrf,
                'HTTP_ORIGIN' => 'https://other.example',
            ], [], [], [], '');

            $sessions = new Sessions($registry, $config);

            expectError('FORBIDDEN', function () use ($sessions, $request): void {
                $sessions->authenticate($request);
            });
        });
    },

    'stale credentials cannot invalidate a newer administrator password' => function (): void {
        withFixture(function (Config $config, Registry $registry): void {
            $oldHash = Credentials::hashPassword('old-test-password');

            $registry->createAdmin('admin', $oldHash);
            $registry->query('UPDATE admin SET password = ?', ['new-password-hash']);

            $sessions = new Sessions($registry, $config);

            expectError('UNAUTHORIZED', function () use ($sessions, $oldHash): void {
                $sessions->start($oldHash);
            });

            expectSame('new-password-hash', $registry->admin()['password']);
        });
    },
];
