import { test } from 'node:test';
import assert from 'node:assert/strict';
import { request as httpRequest } from 'node:http';
import { access, readdir } from 'node:fs/promises';
import { setTimeout as delay } from 'node:timers/promises';
import path from 'node:path';
import { createTestServer, testImage, testPassword } from './support/fixtures.js';
import { verifyPassword, hashPassword, validateLogin } from '../src/security/credentials.js';

function startUpload(server, account, name) {
    const boundary = 'fileharbor-security-test';
    const endpoint = new URL(server.config.origin + account.route + '/upload');

    let resolveResult;
    let rejectResult;

    const result = new Promise(function waitForResponse(resolve, reject) {
        resolveResult = resolve;
        rejectResult = reject;
    });

    const headers = Object.assign({}, account.headers, {
        'Content-Type': 'multipart/form-data; boundary=' + boundary
    });

    const request = httpRequest(endpoint, {
        method: 'POST',
        headers: headers
    });

    request.setTimeout(5000, function onTimeout() {
        request.destroy(new Error('Test upload timed out.'));
    });

    request.once('response', function readResponse(response) {
        let body = '';

        response.on('data', function readChunk(chunk) {
            body += chunk;
        });

        response.once('error', rejectResult);

        response.once('end', function finishResponse() {
            try {
                resolveResult({
                    status: response.statusCode,
                    headers: response.headers,
                    body: JSON.parse(body)
                });
            } catch (error) {
                rejectResult(error);
            }
        });
    });

    request.once('error', rejectResult);

    request.write(
        '--' +
            boundary +
            '\r\n' +
            'Content-Disposition: form-data; name="file"; filename="' +
            name +
            '"\r\n' +
            'Content-Type: image/png\r\n\r\n'
    );

    request.write(testImage);

    function finish() {
        request.end('\r\n--' + boundary + '--\r\n');
    }

    return {
        request: request,
        result: result,
        finish: finish
    };
}

async function waitForUploads(server, expected) {
    const directory = path.join(server.config.state, 'uploads');

    for (let attempt = 0; attempt < 100; attempt += 1) {
        const entries = await readdir(directory);

        if (entries.length === expected) {
            return;
        }

        await delay(20);
    }

    throw new Error('Upload staging did not reach the expected state.');
}

async function accountServer(testContext, overrides = {}) {
    const server = await createTestServer(testContext, overrides);

    await server.setup();
    await server.login();

    const account = await server.account();

    return { server: server, account: account };
}

test('login before setup returns an authentication error, not a server error', async function unconfiguredLogin(testContext) {
    const server = await createTestServer(testContext);

    const result = await server.call('/panel-api/login', {
        method: 'POST',
        headers: { Origin: server.config.origin },
        data: { username: 'admin', password: testPassword }
    });

    assert.equal(result.status, 401);
    assert.equal(result.body.error.code, 'UNAUTHORIZED');
});

test('malformed stored password hashes fail closed', async function invalidPasswordHash() {
    assert.equal(await verifyPassword(testPassword, 'not-a-valid-hash'), false);
});

test('password derivation has a bounded work queue', async function passwordWorkLimit() {
    const work = [];

    for (let index = 0; index < 5; index += 1) {
        work.push(hashPassword(testPassword));
    }

    const results = await Promise.allSettled(work);
    const completed = results.filter(function fulfilled(result) {
        return result.status === 'fulfilled';
    });

    const rejected = results.filter(function failed(result) {
        return result.status === 'rejected';
    });

    assert.equal(completed.length, 4);
    assert.equal(rejected.length, 1);
    assert.equal(rejected[0].reason.code, 'RATE_LIMIT_EXCEEDED');
    assert.equal(await verifyPassword(testPassword, completed[0].value), true);
});

test('usernames cannot end with line breaks or Unicode separators', function invalidUsernames() {
    for (const username of ['admin\n', 'admin\r', 'admin\u2028']) {
        assert.throws(function validate() {
            validateLogin(username, testPassword);
        }, /Username/);
    }
});

test('rate limits apply before parsing malformed JSON', async function earlyRequestLimit(testContext) {
    const server = await createTestServer(testContext, { rateLimit: 2 });
    const statuses = [];

    for (let attempt = 0; attempt < 3; attempt += 1) {
        const result = await fetch(server.config.origin + '/missing', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: '{broken'
        });

        statuses.push(result.status);
        await result.text();
    }

    assert.deepEqual(statuses, [400, 400, 429]);
});

test('rotating a key during an upload prevents its commit', async function revokedUpload(testContext) {
    const fixture = await accountServer(testContext);
    const server = fixture.server;
    const account = fixture.account;
    const upload = startUpload(server, account, 'revoked.png');

    await waitForUploads(server, 1);

    await server.call('/panel-api/accounts/' + account.id + '/rotate', {
        method: 'POST',
        admin: true,
        data: {}
    });

    upload.finish();

    const result = await upload.result;

    assert.equal(result.status, 401);
    await assert.rejects(access(path.join(server.config.storage, account.id, 'revoked.png')));
    await waitForUploads(server, 0);
});

test('removing upload permission during a request prevents its commit', async function changedScope(testContext) {
    const fixture = await accountServer(testContext);
    const server = fixture.server;
    const account = fixture.account;
    const upload = startUpload(server, account, 'forbidden.png');

    await waitForUploads(server, 1);

    await server.call('/panel-api/accounts/' + account.id, {
        method: 'PATCH',
        admin: true,
        data: { name: 'Reader', enabled: true, permissions: ['files:list'] }
    });

    upload.finish();

    const result = await upload.result;

    assert.equal(result.status, 403);
    await assert.rejects(access(path.join(server.config.storage, account.id, 'forbidden.png')));
    await waitForUploads(server, 0);
});

test('active upload slots are bounded and released after success', async function uploadConcurrency(testContext) {
    const fixture = await accountServer(testContext, { maxUploads: 1 });
    const server = fixture.server;
    const account = fixture.account;
    const first = startUpload(server, account, 'first.png');

    await waitForUploads(server, 1);

    const rejected = await server.send(account, 'second.png');

    assert.equal(rejected.status, 429);

    first.finish();

    assert.equal((await first.result).status, 201);
    assert.equal((await server.send(account, 'third.png')).status, 201);
    await waitForUploads(server, 0);
});

test('disconnecting an upload removes staging data and releases its slot', async function interruptedUpload(testContext) {
    const fixture = await accountServer(testContext, { maxUploads: 1 });
    const server = fixture.server;
    const account = fixture.account;
    const upload = startUpload(server, account, 'interrupted.png');

    const disconnected = upload.result.catch(function expectedDisconnect(error) {
        return error;
    });

    await waitForUploads(server, 1);

    upload.request.destroy();

    await disconnected;
    await waitForUploads(server, 0);

    const next = await server.send(account, 'after-disconnect.png');

    assert.equal(next.status, 201);
    await assert.rejects(access(path.join(server.config.storage, account.id, 'interrupted.png')));
});

test('oversized streaming requests are closed without waiting for the entire body', async function oversizedStream(testContext) {
    const fixture = await accountServer(testContext, { maxBytes: 1024 * 1024 });
    const server = fixture.server;
    const upload = startUpload(server, fixture.account, 'oversized.png');

    upload.request.write(Buffer.alloc(1024 * 1024 + 65536));

    const result = await upload.result;

    assert.equal(result.status, 413);
    assert.equal(result.headers.connection, 'close');
    await waitForUploads(server, 0);
});

test('unauthenticated incomplete uploads are rejected without staging data', async function anonymousUpload(testContext) {
    const fixture = await accountServer(testContext);

    const anonymous = {
        route: fixture.account.route,
        headers: {}
    };

    const upload = startUpload(fixture.server, anonymous, 'anonymous.png');
    const result = await upload.result;

    assert.equal(result.status, 401);
    assert.equal(result.headers.connection, 'close');
    await waitForUploads(fixture.server, 0);
});

test('private state and source paths are not public HTTP resources', async function privateRoutes(testContext) {
    const server = await createTestServer(testContext);

    for (const route of [
        '/panel/.env',
        '/panel/fileharbor.sqlite',
        '/src/app.js',
        '/var/fileharbor.sqlite'
    ]) {
        const response = await fetch(server.config.origin + route);

        assert.equal(response.status, 404);
        await response.text();
    }
});
