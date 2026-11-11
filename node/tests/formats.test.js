import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { loadConfig } from '../src/config/environment.js';
import { createTestServer, testSetupToken } from './support/fixtures.js';

const customFormats = '{"image/tiff":["tif","tiff"]}';
const types = [
    'image/gif',
    'audio/mpeg',
    'audio/wav',
    'application/zip',
    'application/gzip',
    'image/tiff'
];
const extensions = ['gif', 'mp3', 'wav', 'zip', 'gz', 'tiff'];

test('formats configuration validates mappings and aliases', function formatSettings() {
    const settings = { FILEHARBOR_ENV: 'development', FILEHARBOR_SETUP_TOKEN: testSetupToken };
    const config = loadConfig({
        ...settings,
        ALLOWED_MIME_TYPES: types.join(','),
        MIME_TYPE_EXTENSIONS: customFormats
    });

    assert.deepEqual(config.allowed, types);
    assert.deepEqual(config.formats['image/tiff'], ['tif', 'tiff']);
    assert.deepEqual(loadConfig({ ...settings, ALLOWED_MIME_TYPES: 'audio/x-wav' }).allowed, [
        'audio/wav'
    ]);

    for (const input of [
        '[]',
        'null',
        '{',
        '{"image/tiff":[]}',
        '{"image/tiff":"tif"}',
        '{"image/tiff":["php"]}',
        '{"image/svg+xml":["svg"]}',
        '{"text/html":["html"]}',
        '{"image/tiff":["../tif"]}',
        '{"image/tiff":[12]}'
    ]) {
        assert.throws(function invalidMapping() {
            loadConfig({ ...settings, MIME_TYPE_EXTENSIONS: input });
        });
    }

    for (const input of ['image/tiff', '*/*', '', 'image/gif,']) {
        assert.throws(function invalidAllowlist() {
            loadConfig({ ...settings, ALLOWED_MIME_TYPES: input });
        }, /registered/);
    }
});

test('configured formats upload and download with the same validation', async function additionalFormats(testContext) {
    const server = await createTestServer(
        testContext,
        {},
        {
            ALLOWED_MIME_TYPES: types.join(','),
            MIME_TYPE_EXTENSIONS: customFormats
        }
    );

    await server.setup();
    await server.login();

    const account = await server.account();

    for (let index = 0; index < extensions.length; index += 1) {
        const name = 'sample.' + extensions[index];
        const bytes = await readFile(new URL('./fixtures/' + name, import.meta.url));
        const uploaded = await server.send(account, name, bytes);

        assert.equal(uploaded.status, 201, name);
        assert.equal(uploaded.body.data.mime_type, types[index]);

        const response = await fetch(server.config.origin + '/' + account.id + '/' + name);

        assert.equal(response.status, 200);
        assert.equal(response.headers.get('content-type'), types[index]);
        assert.deepEqual(Buffer.from(await response.arrayBuffer()), bytes);

        if (['zip', 'gz', 'tiff'].includes(extensions[index])) {
            assert.equal(response.headers.get('content-disposition'), 'attachment');
        } else {
            assert.equal(response.headers.get('content-disposition'), null);
        }
    }

    const gif = await readFile(new URL('./fixtures/sample.gif', import.meta.url));

    assert.equal((await server.send(account, 'wrong.zip', gif)).status, 415);
    assert.equal(
        (await server.send(account, 'fake.tiff', Buffer.from('<html>not an image</html>'))).status,
        415
    );

    await writeFile(path.join(server.config.storage, account.id, 'wrong.zip'), gif);

    assert.equal((await fetch(server.config.origin + '/' + account.id + '/wrong.zip')).status, 404);
});

test('additional formats remain disabled by default', async function defaultAllowlist(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const account = await server.account();
    const gif = await readFile(new URL('./fixtures/sample.gif', import.meta.url));

    assert.equal((await server.send(account, 'sample.gif', gif)).status, 415);

    await writeFile(path.join(server.config.storage, account.id, 'sample.gif'), gif);

    assert.equal(
        (await fetch(server.config.origin + '/' + account.id + '/sample.gif')).status,
        404
    );
});
