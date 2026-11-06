<?php

declare(strict_types=1);

use FileHarbor\Config\Config;
use FileHarbor\Security\Credentials;
use FileHarbor\Repositories\Registry;

return [
    'private database paths must not be directories' => function (): void {
        withFixture(function (Config $config): void {
            $state = $config->state . '/invalid';

            mkdir($state);
            mkdir($state . '/fileharbor.sqlite');

            expectError('CONFIG_ERROR', function () use ($state): void {
                new Registry($state);
            });
        });
    },

    'passwords beyond 72 bytes remain distinct' => function (): void {
        $first = str_repeat('a', 100) . 'first';
        $second = str_repeat('a', 100) . 'second';
        $encoded = Credentials::hashPassword($first);

        expectSame(true, Credentials::verifyPassword($first, $encoded));
        expectSame(false, Credentials::verifyPassword($second, $encoded));
    },

    'setup cannot replace the initial administrator' => function (): void {
        withFixture(function (Config $config, Registry $registry): void {
            $registry->createAdmin('admin', Credentials::hashPassword('test-password-123'));

            expectError('SETUP_COMPLETE', function () use ($registry): void {
                $registry->createAdmin('other', 'not-a-real-password-hash');
            });

            expectSame('admin', $registry->admin()['username']);
        });
    },

    'keys are hashed, scoped to their account, revocable and disabled' => function (): void {
        withFixture(function (Config $config, Registry $registry): void {
            $account = $registry->createAccount('Blog', ['files:list']);
            $other = $registry->createAccount('Other', []);

            $stored = $registry->account($account['id']);

            expectSame(false, $stored['key_hash'] === $account['api_key']);
            expectSame(null, $registry->authorize($other['id'], $account['api_key']));
            expectSame(['files:list'], $registry->authorize($account['id'], $account['api_key'])['permissions']);

            $rotated = $registry->rotateKey($account['id']);

            expectSame(null, $registry->authorize($account['id'], $account['api_key']));
            expectSame($account['id'], $registry->authorize($account['id'], $rotated)['id']);

            $registry->updateAccount($account['id'], 'Blog', ['files:list'], false);

            expectSame(null, $registry->authorize($account['id'], $rotated));
        });
    },

    'public account lists contain no key material' => function (): void {
        withFixture(function (Config $config, Registry $registry): void {
            $account = $registry->createAccount('Blog', []);
            $listed = $registry->accounts();

            expectSame(false, array_key_exists('key_hash', $listed[0]));
            expectSame(false, array_key_exists('api_key', $listed[0]));
            expectSame(true, $listed[0]['enabled']);
        });
    },

    'SQLite stores authentication state but no per-file records' => function (): void {
        withFixture(function (Config $config, Registry $registry): void {
            $tables = $registry->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"
            )->fetchAll(PDO::FETCH_COLUMN);

            expectSame(['accounts', 'admin', 'rate_limits', 'sessions'], $tables);
        });
    },
];
