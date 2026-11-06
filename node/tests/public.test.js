import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createTestServer } from './support/fixtures.js';
import { checkPublicContract } from './support/contract.js';

test('public API follows the shared contract', async function publicContract(testContext) {
    const server = await createTestServer(testContext);

    await checkPublicContract(server.config.origin);
});

test('panel assets are available with security headers', async function panelAssets(testContext) {
    const server = await createTestServer(testContext);
    const panel = await fetch(server.config.origin + '/panel/');

    assert.equal(panel.status, 200);
    assert.match(panel.headers.get('content-security-policy'), /frame-ancestors 'none'/);

    const page = await panel.text();

    assert.match(page, /setup-form/);

    for (const asset of ['script.js', 'style.css']) {
        const response = await fetch(server.config.origin + '/panel/' + asset);

        assert.equal(response.status, 200);
    }
});
