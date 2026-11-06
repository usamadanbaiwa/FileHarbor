import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, mkdtemp, rm } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { openRegistry } from '../src/repositories/registry.js';

test('SQLite database and journal paths must be regular files', async function databasePaths(testContext) {
    const base = fileURLToPath(new URL('./.state/', import.meta.url));

    await mkdir(base, { recursive: true });

    const root = await mkdtemp(path.join(base, 'test-database-'));

    testContext.after(async function cleanup() {
        await rm(root, { recursive: true, force: true });
    });

    for (const suffix of ['', '-wal', '-shm']) {
        const state = path.join(root, 'case-' + suffix);

        await mkdir(state);
        await mkdir(path.join(state, 'fileharbor.sqlite' + suffix));

        await assert.rejects(openRegistry(state), function configurationError(error) {
            return error.code === 'CONFIG_ERROR';
        });
    }
});
