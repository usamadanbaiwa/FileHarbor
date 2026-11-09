import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, writeFile, symlink } from 'node:fs/promises';
import path from 'node:path';
import { createTestServer, testImage } from './support/fixtures.js';

test('public files support GET, HEAD, conditional requests, and video ranges', async function content(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const account = await server.account();

    await server.call(account.route + '/directories', {
        method: 'POST',
        headers: account.headers,
        data: { source: 'assets' }
    });

    const uploaded = await server.send(account, 'image.png', testImage, { folder: 'assets' });

    assert.equal(uploaded.status, 201);

    const url = server.config.origin + '/' + account.id + '/assets/image.png';
    const response = await fetch(url);

    assert.equal(response.status, 200);
    assert.equal(response.headers.get('content-type'), 'image/png');
    assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
    assert.match(response.headers.get('content-security-policy'), /sandbox/);
    assert.deepEqual(Buffer.from(await response.arrayBuffer()), testImage);

    const head = await fetch(url, { method: 'HEAD' });

    assert.equal(head.status, 200);
    assert.equal(Number(head.headers.get('content-length')), testImage.length);
    assert.equal((await head.arrayBuffer()).byteLength, 0);

    const cached = await fetch(url, {
        headers: { 'If-None-Match': response.headers.get('etag'), 'Cache-Control': 'max-age=0' }
    });

    assert.equal(cached.status, 304);

    const video = Buffer.from(
        '000000186674797069736f6d0000020069736f6d6d703432000000086d646174',
        'hex'
    );
    await writeFile(path.join(server.config.storage, account.id, 'clip.mp4'), video);

    const videoUrl = server.config.origin + '/' + account.id + '/clip.mp4';
    const ranged = await fetch(videoUrl, { headers: { Range: 'bytes=4-11' } });

    assert.equal(ranged.status, 206);
    assert.equal(ranged.headers.get('content-type'), 'video/mp4');
    assert.equal(ranged.headers.get('content-range'), 'bytes 4-11/' + video.length);
    assert.deepEqual(Buffer.from(await ranged.arrayBuffer()), video.subarray(4, 12));

    const suffix = await fetch(videoUrl, { headers: { Range: 'bytes=-4' } });

    assert.equal(suffix.status, 206);
    assert.deepEqual(Buffer.from(await suffix.arrayBuffer()), video.subarray(-4));

    const invalid = await fetch(videoUrl, { headers: { Range: 'bytes=9999-' } });

    assert.equal(invalid.status, 416);
    assert.equal(invalid.headers.get('content-range'), 'bytes */' + video.length);
});

test('public serving rejects unsafe paths, directories, missing files, and active content', async function unsafeContent(testContext) {
    const server = await createTestServer(testContext);
    const id = 'a'.repeat(22);
    const root = path.join(server.config.storage, id);

    await mkdir(root);
    await writeFile(path.join(root, 'unsafe.png'), '<html><script>alert(1)</script></html>');

    for (const source of [
        'missing.png',
        'unsafe.png',
        '.',
        '%2e%2e%2fprivate.png',
        '%5cprivate.png',
        '%ZZ'
    ]) {
        const response = await fetch(server.config.origin + '/' + id + '/' + source);

        assert.ok(response.status === 400 || response.status === 404);
    }

    assert.equal(
        (await fetch(server.config.origin + '/storage/' + id + '/unsafe.png')).status,
        404
    );
    assert.equal((await fetch(server.config.origin + '/panel-api/accounts')).status, 401);
});

test('public serving can be disabled', async function disabledContent(testContext) {
    const server = await createTestServer(testContext, { serveFiles: false });
    const id = 'a'.repeat(22);

    await mkdir(path.join(server.config.storage, id));
    await writeFile(path.join(server.config.storage, id, 'image.png'), testImage);

    const response = await fetch(server.config.origin + '/' + id + '/image.png');

    assert.equal(response.status, 404);
});

test('public serving rejects symlink files and directories', async function linkedContent(testContext) {
    const server = await createTestServer(testContext);
    const id = 'a'.repeat(22);
    const root = path.join(server.config.storage, id);
    const outside = path.join(server.root, 'outside');

    await mkdir(root);
    await mkdir(outside);
    await writeFile(path.join(outside, 'image.png'), testImage);

    try {
        await symlink(outside, path.join(root, 'linked'), 'junction');
    } catch (error) {
        if (error.code === 'EPERM') {
            testContext.skip('Creating symlinks requires permission.');
            return;
        }

        throw error;
    }

    const linkedDirectory = await fetch(server.config.origin + '/' + id + '/linked/image.png');

    assert.equal(linkedDirectory.status, 400);

    try {
        await symlink(path.join(outside, 'image.png'), path.join(root, 'image.png'), 'file');
    } catch (error) {
        if (error.code === 'EPERM') {
            testContext.diagnostic(
                'File symlink check unavailable; directory symlink check passed.'
            );
            return;
        }

        throw error;
    }

    const linkedFile = await fetch(server.config.origin + '/' + id + '/image.png');

    assert.equal(linkedFile.status, 400);
});
