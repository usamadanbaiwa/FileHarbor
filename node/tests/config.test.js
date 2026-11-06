import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadConfig } from '../src/config/environment.js';
import { testSetupToken } from './support/fixtures.js';

test('configuration rejects missing setup secrets', function missingSecret() {
    assert.throws(function readSettings() {
        loadConfig({
            FILEHARBOR_ENV: 'development'
        });
    }, /SETUP_TOKEN/);
});

test('production configuration requires HTTPS', function productionOrigin() {
    assert.throws(function readSettings() {
        loadConfig({
            FILEHARBOR_SETUP_TOKEN: testSetupToken,
            FILEHARBOR_URL: 'http://example.com'
        });
    }, /HTTPS/);
});

test('configuration rejects overlapping state and storage', function overlappingRoots() {
    assert.throws(function readSettings() {
        loadConfig({
            STORAGE_PATH: './same',
            FILEHARBOR_STATE_PATH: './same/private'
        });
    }, /separate/);
});

test('configuration rejects unsupported content types', function unsupportedContent() {
    assert.throws(function readSettings() {
        loadConfig({
            FILEHARBOR_ENV: 'development',
            FILEHARBOR_SETUP_TOKEN: testSetupToken,
            ALLOWED_MIME_TYPES: 'text/html'
        });
    }, /Allowed types/);
});

test('private state cannot be placed inside the public panel directory', function publicState() {
    const directory = fileURLToPath(new URL('../public/panel/private-state/', import.meta.url));

    assert.throws(function readSettings() {
        loadConfig({
            FILEHARBOR_ENV: 'development',
            FILEHARBOR_SETUP_TOKEN: testSetupToken,
            FILEHARBOR_STATE_PATH: directory
        });
    }, /outside/);
});

test('data directories cannot include application source', function sourceOverlap() {
    const directory = fileURLToPath(new URL('../src/', import.meta.url));

    assert.throws(function readSettings() {
        loadConfig({
            FILEHARBOR_ENV: 'development',
            FILEHARBOR_SETUP_TOKEN: testSetupToken,
            STORAGE_PATH: directory
        });
    }, /outside/);
});

test('directory overlap checks account for platform root paths', function rootOverlap() {
    const root = path.parse(process.cwd()).root;

    assert.throws(function readSettings() {
        loadConfig({
            STORAGE_PATH: root,
            FILEHARBOR_STATE_PATH: path.join(root, 'private-state')
        });
    }, /separate/);
});
