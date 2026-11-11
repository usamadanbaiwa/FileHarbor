import assert from 'node:assert/strict';
import { mkdir, mkdtemp, rm, readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { createApp } from '../../src/app.js';
import { loadConfig } from '../../src/config/environment.js';

export const testPassword = 'test-only-password-123';
export const testSetupToken = 'test-only-setup-token-with-32-character';

export const testImage = await readFile(new URL('../fixtures/image.png', import.meta.url));

const allPermissions = [
    'files:upload',
    'files:list',
    'files:delete',
    'directories:create',
    'directories:list',
    'directories:delete'
];

export async function createTestServer(testContext, overrides = {}, environment = {}) {
    const baseDirectory = fileURLToPath(new URL('../.state/', import.meta.url));

    await mkdir(baseDirectory, {
        recursive: true
    });

    const root = await mkdtemp(path.join(baseDirectory, 'test-'));

    // Add the test environment file don't use the real one
    const config = loadConfig({
        FILEHARBOR_ENV: 'development',
        FILEHARBOR_SETUP_TOKEN: testSetupToken,
        STORAGE_PATH: path.join(root, 'storage'),
        FILEHARBOR_STATE_PATH: path.join(root, 'state'),
        ...environment
    });

    Object.assign(config, overrides);

    let service;

    testContext.after(async function cleanup() {
        try {
            if (service) {
                await service.close();
            }
        } finally {
            await rm(root, {
                recursive: true,
                force: true
            });
        }
    });

    service = await createApp(config);

    const server = service.app.listen(0, '127.0.0.1');

    await new Promise(function waitForServer(resolve) {
        server.once('listening', resolve);
    });

    config.origin = 'http://127.0.0.1:' + server.address().port;

    const closeService = service.close;

    service.close = async function closeServer() {
        server.closeAllConnections();

        await new Promise(function waitForClose(resolve) {
            server.close(resolve);
        });

        closeService();
    };

    let cookie = '';
    let csrfToken = '';

    async function call(route, options = {}) {
        const headers = {};
        let body;

        if (options.data !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(options.data);
        }

        if (options.admin) {
            headers.Cookie = cookie;
            headers.Origin = config.origin;
            headers['X-CSRF-Token'] = csrfToken;
        }

        if (options.form) {
            body = options.form;
        }

        Object.assign(headers, options.headers);

        const response = await fetch(config.origin + route, {
            method: options.method || 'GET',
            headers: headers,
            body: body
        });

        const result = await response.json();

        return {
            status: response.status,
            body: result,
            response: response
        };
    }

    async function setup() {
        return call('/panel-api/setup', {
            method: 'POST',
            data: {
                username: 'admin',
                password: testPassword,
                setup_token: testSetupToken
            }
        });
    }

    async function login() {
        const result = await call('/panel-api/login', {
            method: 'POST',
            headers: {
                Origin: config.origin
            },
            data: {
                username: 'admin',
                password: testPassword
            }
        });

        cookie = '';
        csrfToken = '';

        const sessionCookie = result.response.headers.get('set-cookie');

        if (sessionCookie) {
            cookie = sessionCookie.split(';')[0];
        }

        if (result.body.data && result.body.data.csrf_token) {
            csrfToken = result.body.data.csrf_token;
        }

        return result;
    }

    async function account(scopes = allPermissions) {
        const result = await call('/panel-api/accounts', {
            method: 'POST',
            admin: true,
            data: {
                name: 'Test account',
                permissions: scopes
            }
        });

        assert.equal(result.status, 201);

        const created = result.body.data;

        return {
            id: created.id,
            api_key: created.api_key,
            route: '/fileharbor/' + created.id + '/api',
            headers: {
                Authorization: 'Bearer ' + created.api_key
            }
        };
    }

    async function send(account, name = 'image.png', bytes = testImage, fields = {}) {
        const form = new FormData();

        // Send the file first to check that later fields are still honored.
        form.append('file', new Blob([bytes]), name);

        for (const [field, value] of Object.entries(fields)) {
            form.append(field, value);
        }

        return call(account.route + '/upload', {
            method: 'POST',
            form: form,
            headers: account.headers
        });
    }

    return {
        root: root,
        config: config,
        service: service,
        call: call,
        setup: setup,
        login: login,
        account: account,
        send: send
    };
}
