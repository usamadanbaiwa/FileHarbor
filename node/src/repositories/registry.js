import { DatabaseSync } from 'node:sqlite';
import { mkdir, chmod } from 'node:fs/promises';
import path from 'node:path';
import { generateSecret, hashSecret, secretsMatch } from '../security/credentials.js';
import { requireValue } from '../http/responses.js';
import { rejectLinkedFile } from '../utils/paths.js';

export const permissions = [
    'files:upload',
    'files:delete',
    'files:list',
    'directories:create',
    'directories:list',
    'directories:delete'
];

class Registry {
    constructor(database) {
        this.db = database;
    }

    admin() {
        return this.db.prepare('SELECT * FROM admin WHERE id = 1').get();
    }

    createAdmin(username, password) {
        // The fixed primary key also prevents concurrent setup requests creating two admins.
        const statement = this.db.prepare(
            'INSERT OR IGNORE INTO admin (id, username, password) VALUES (1, ?, ?)'
        );

        const result = statement.run(username, password);

        requireValue(result.changes === 1, 'SETUP_COMPLETE', 'Setup has already completed.', 409);
    }

    updateAdmin(username, password) {
        const statement = this.db.prepare(
            'UPDATE admin SET username = ?, password = ? WHERE id = 1'
        );

        return statement.run(username, password);
    }

    list() {
        const statement = this.db.prepare(
            'SELECT id, name, permissions, enabled FROM accounts ORDER BY name, id'
        );

        const accounts = statement.all();

        return accounts.map(function publicAccount(account) {
            return {
                id: account.id,
                name: account.name,
                permissions: JSON.parse(account.permissions),
                enabled: Boolean(account.enabled)
            };
        });
    }

    get(id) {
        return this.db.prepare('SELECT * FROM accounts WHERE id = ?').get(id);
    }

    create(name, scopes) {
        const id = generateSecret().slice(0, 22);
        const key = generateSecret();

        const statement = this.db.prepare(
            'INSERT INTO accounts (id, name, permissions, enabled, key_hash) VALUES (?, ?, ?, ?, ?)'
        );

        statement.run(id, name, JSON.stringify(scopes), 1, hashSecret(key));

        return {
            id: id,
            name: name,
            permissions: scopes,
            enabled: true,
            api_key: key,
            public_prefix: id
        };
    }

    update(id, name, scopes, enabled) {
        const statement = this.db.prepare(
            'UPDATE accounts SET name = ?, permissions = ?, enabled = ? WHERE id = ?'
        );

        return statement.run(name, JSON.stringify(scopes), Number(enabled), id);
    }

    rotate(id) {
        const key = generateSecret();

        const statement = this.db.prepare('UPDATE accounts SET key_hash = ? WHERE id = ?');

        statement.run(hashSecret(key), id);

        return key;
    }

    remove(id) {
        return this.db.prepare('DELETE FROM accounts WHERE id = ?').run(id);
    }

    authorize(id, key) {
        const account = this.get(id);
        let storedHash = 'not-a-real-hash';

        if (account) {
            storedHash = account.key_hash;
        }

        const matched = secretsMatch(hashSecret(key), storedHash);

        if (!account || !account.enabled || !matched) {
            return null;
        }

        return {
            id: account.id,
            name: account.name,
            permissions: JSON.parse(account.permissions),
            enabled: account.enabled,
            key_hash: account.key_hash
        };
    }
}

export async function openRegistry(state) {
    await mkdir(state, {
        recursive: true,
        mode: 0o700
    });

    await chmod(state, 0o700);

    const filename = path.join(state, 'fileharbor.sqlite');

    for (const suffix of ['', '-wal', '-shm']) {
        await rejectLinkedFile(filename + suffix);
    }

    const database = new DatabaseSync(filename);

    await chmod(filename, 0o600);

    database.exec(`
        PRAGMA journal_mode = WAL;
        PRAGMA busy_timeout = 5000;

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
    `);

    return new Registry(database);
}
