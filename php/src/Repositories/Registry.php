<?php

declare(strict_types=1);

namespace FileHarbor\Repositories;

use FileHarbor\Http\ApiError;
use FileHarbor\Security\Credentials;

use PDO;
use Throwable;

final class Registry
{
    public const PERMISSIONS = [
        'files:upload',
        'files:delete',
        'files:list',
        'directories:create',
        'directories:list',
        'directories:delete',
    ];

    public PDO $database;

    public function __construct(string $state)
    {
        chmod($state, 0700);

        $filename = $state . '/fileharbor.sqlite';

        foreach (['', '-wal', '-shm'] as $suffix) {
            $databasePath = $filename . $suffix;

            ApiError::require(
                !is_link($databasePath) &&
                (!file_exists($databasePath) || is_file($databasePath)),
                'CONFIG_ERROR',
                'Private database files must be regular files.',
                500
            );
        }

        $this->database = new PDO('sqlite:' . $filename, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        chmod($filename, 0600);

        $this->database->exec('PRAGMA busy_timeout = 5000');
        $this->database->exec('PRAGMA journal_mode = WAL');

        $this->database->exec('
            CREATE TABLE IF NOT EXISTS admin (
                id INTEGER PRIMARY KEY CHECK(id = 1),
                username TEXT NOT NULL,
                password TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS accounts (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                permissions TEXT NOT NULL,
                enabled INTEGER NOT NULL,
                key_hash TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                csrf TEXT NOT NULL,
                expires INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS rate_limits (
                id TEXT PRIMARY KEY,
                count INTEGER NOT NULL,
                expires INTEGER NOT NULL
            );
        ');
    }

    public function transaction(callable $operation): mixed
    {
        $this->database->exec('BEGIN IMMEDIATE');

        try {
            $result = $operation();
            $this->database->exec('COMMIT');

            return $result;
        } catch (Throwable $error) {
            $this->database->exec('ROLLBACK');

            throw $error;
        }
    }

    public function query(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    public function admin(): ?array
    {
        $admin = $this->query('SELECT * FROM admin WHERE id = 1')->fetch();

        if ($admin === false) {
            return null;
        }

        return $admin;
    }

    public function createAdmin(string $username, string $password): void
    {
        $result = $this->query(
            'INSERT OR IGNORE INTO admin (id, username, password) VALUES (1, ?, ?)',
            [$username, $password]
        );

        ApiError::require(
            $result->rowCount() === 1,
            'SETUP_COMPLETE',
            'Setup has already completed.',
            409
        );
    }

    public function accounts(): array
    {
        $accounts = $this->query(
            'SELECT id, name, permissions, enabled FROM accounts ORDER BY name, id'
        )->fetchAll();

        foreach ($accounts as &$account) {
            $account['permissions'] = json_decode($account['permissions'], true, 512, JSON_THROW_ON_ERROR);
            $account['enabled'] = (bool) $account['enabled'];
        }

        return $accounts;
    }

    public function account(string $id): ?array
    {
        $account = $this->query('SELECT * FROM accounts WHERE id = ?', [$id])->fetch();

        if ($account === false) {
            return null;
        }

        return $account;
    }

    public function createAccount(string $name, array $permissions): array
    {
        $id = substr(Credentials::secret(), 0, 22);
        $key = Credentials::secret();

        $this->query(
            'INSERT INTO accounts (id, name, permissions, enabled, key_hash) VALUES (?, ?, ?, 1, ?)',
            [$id, $name, json_encode($permissions, JSON_THROW_ON_ERROR), Credentials::digest($key)]
        );

        return [
            'id' => $id,
            'name' => $name,
            'permissions' => $permissions,
            'enabled' => true,
            'api_key' => $key,
            'public_prefix' => $id,
        ];
    }

    public function updateAccount(string $id, string $name, array $permissions, bool $enabled): void
    {
        $this->query(
            'UPDATE accounts SET name = ?, permissions = ?, enabled = ? WHERE id = ?',
            [$name, json_encode($permissions, JSON_THROW_ON_ERROR), (int) $enabled, $id]
        );
    }

    public function rotateKey(string $id): string
    {
        $key = Credentials::secret();

        $this->query(
            'UPDATE accounts SET key_hash = ? WHERE id = ?',
            [Credentials::digest($key), $id]
        );

        return $key;
    }

    public function removeAccount(string $id): void
    {
        $this->query('DELETE FROM accounts WHERE id = ?', [$id]);
    }

    public function authorize(string $id, string $key): ?array
    {
        $account = $this->account($id);
        $storedHash = 'missing';

        if ($account !== null) {
            $storedHash = $account['key_hash'];
        }

        $validKey = Credentials::matches(Credentials::digest($key), $storedHash);

        if ($account === null || !$account['enabled'] || !$validKey) {
            return null;
        }

        $account['permissions'] = json_decode($account['permissions'], true, 512, JSON_THROW_ON_ERROR);

        return $account;
    }
}
