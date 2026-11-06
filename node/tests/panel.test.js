import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createTestServer, testPassword, testImage } from './support/fixtures.js';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

test('setup, sessions, CSRF and credential storage', async function panelSecurity(testContext) {
    const server = await createTestServer(testContext);

    const invalidSetup = await server.call('/panel-api/setup', {
        method: 'POST',
        data: {
            username: 'admin',
            password: testPassword,
            setup_token: 'wrong'
        }
    });

    assert.equal(invalidSetup.status, 401);

    const setup = await server.setup();
    const repeatedSetup = await server.setup();
    const anonymousAccounts = await server.call('/panel-api/accounts');
    const login = await server.login();

    assert.equal(setup.status, 201);
    assert.equal(repeatedSetup.status, 409);
    assert.equal(anonymousAccounts.status, 401);
    assert.equal(login.status, 200);

    const invalidCsrf = await server.call('/panel-api/accounts', {
        method: 'POST',
        admin: true,
        data: {},
        headers: {
            'X-CSRF-Token': 'wrong'
        }
    });

    assert.equal(invalidCsrf.status, 403);

    const invalidOrigin = await server.call('/panel-api/accounts', {
        method: 'POST',
        admin: true,
        data: {},
        headers: {
            Origin: 'https://attacker.example'
        }
    });

    assert.equal(invalidOrigin.status, 403);

    const account = await server.account();
    const storedAccount = server.service.registry.get(account.id);
    const storedAdmin = server.service.registry.admin();

    assert.notEqual(storedAccount.key_hash, account.api_key);
    assert.notEqual(storedAdmin.password, testPassword);

    const listed = await server.call('/panel-api/accounts', {
        admin: true
    });

    const accountList = JSON.stringify(listed.body);

    assert.ok(!accountList.includes(account.api_key));
    assert.ok(!accountList.includes(storedAccount.key_hash));
});

test('API keys enforce scopes, ownership, rotation and disabling', async function accountAccess(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const owner = await server.account();
    const reader = await server.account(['files:list']);

    const missingKey = await server.call(owner.route + '/files');
    const forbiddenUpload = await server.send(reader);

    const otherAccount = await server.call(owner.route + '/files', {
        headers: reader.headers
    });

    const upload = await server.send(owner);

    assert.equal(missingKey.status, 401);
    assert.equal(forbiddenUpload.status, 403);
    assert.equal(otherAccount.status, 401);
    assert.equal(upload.status, 201);

    const rotated = await server.call('/panel-api/accounts/' + owner.id + '/rotate', {
        method: 'POST',
        admin: true,
        data: {}
    });

    assert.equal(rotated.status, 200);

    const oldKey = await server.call(owner.route + '/files', {
        headers: owner.headers
    });

    const newHeaders = {
        Authorization: 'Bearer ' + rotated.body.data.api_key
    };

    const newKey = await server.call(owner.route + '/files', {
        headers: newHeaders
    });

    assert.equal(oldKey.status, 401);
    assert.equal(newKey.status, 200);

    await server.call('/panel-api/accounts/' + owner.id, {
        method: 'PATCH',
        admin: true,
        data: {
            name: 'Disabled',
            enabled: false,
            permissions: ['files:list']
        }
    });

    const disabled = await server.call(owner.route + '/files', {
        headers: newHeaders
    });

    assert.equal(disabled.status, 401);

    await server.call('/panel-api/accounts/' + owner.id, {
        method: 'DELETE',
        admin: true
    });

    const retainedFile = await readFile(path.join(server.config.storage, owner.id, 'image.png'));

    assert.deepEqual(retainedFile, testImage);
});

test('credential changes invalidate existing sessions', async function changeCredentials(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const changed = await server.call('/panel-api/credentials', {
        method: 'POST',
        admin: true,
        data: {
            current_password: testPassword,
            username: 'newadmin',
            password: 'a-new-test-password-123'
        }
    });

    const oldSession = await server.call('/panel-api/accounts', {
        admin: true
    });

    const oldLogin = await server.login();

    assert.equal(changed.status, 200);
    assert.equal(oldSession.status, 401);
    assert.equal(oldLogin.status, 401);
});

test('logout revokes the current session', async function logout(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const loggedOut = await server.call('/panel-api/logout', {
        method: 'POST',
        admin: true,
        data: {}
    });

    const oldSession = await server.call('/panel-api/accounts', {
        admin: true
    });

    assert.equal(loggedOut.status, 200);
    assert.equal(oldSession.status, 401);
});

test('setup rate limits and unknown routes use standard errors', async function errorResponses(testContext) {
    const server = await createTestServer(testContext);

    for (let attempt = 0; attempt < 10; attempt += 1) {
        await server.call('/panel-api/setup', {
            method: 'POST',
            data: {}
        });
    }

    const limitedSetup = await server.setup();
    const missingRoute = await server.call('/missing');

    assert.equal(limitedSetup.status, 429);
    assert.equal(missingRoute.status, 404);
    assert.deepEqual(Object.keys(missingRoute.body), ['success', 'error']);
});
