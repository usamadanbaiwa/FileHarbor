import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createTestServer, testImage } from './support/fixtures.js';

const videoHeader = await readFile(new URL('./fixtures/video-header.mp4', import.meta.url));

test('MP4 uploads accept 25 MiB and reject one byte above the default', async function videoLimit(testContext) {
    
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const account = await server.account();
    const video = Buffer.alloc(25 * 1024 * 1024);

    videoHeader.copy(video);

    const accepted = await server.send(account, 'clip.mp4', video);

    assert.equal(accepted.status, 201);
    assert.equal(accepted.body.data.mime_type, 'video/mp4');
    assert.equal(accepted.body.data.size, 25 * 1024 * 1024);

    const oversized = Buffer.alloc(25 * 1024 * 1024 + 1);

    videoHeader.copy(oversized);

    const rejected = await server.send(account, 'oversized.mp4', oversized);

    assert.equal(rejected.status, 413);
});

test('MP4 uploads require matching content and filename', async function videoTypes(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const account = await server.account();
    const fakeVideo = await server.send(account, 'fake.mp4', testImage);
    const wrongExtension = await server.send(account, 'fake.png', videoHeader);

    assert.equal(fakeVideo.status, 415);
    assert.equal(wrongExtension.status, 415);
});
