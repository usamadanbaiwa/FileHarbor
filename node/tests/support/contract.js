import assert from 'node:assert/strict';

// These checks can run against either runtime without importing server code.
export async function checkPublicContract(baseUrl) {
    const health = await fetch(baseUrl + '/api/health');

    assert.equal(health.status, 200);

    const healthBody = await health.json();

    assert.deepEqual(healthBody, {
        success: true,
        data: {
            status: 'ok'
        }
    });

    const denied = await fetch(baseUrl + '/fileharbor/unknown/api/files');

    assert.equal(denied.status, 401);

    const errorBody = await denied.json();

    assert.equal(errorBody.success, false);
    assert.equal(errorBody.error.code, 'UNAUTHORIZED');
    assert.equal(typeof errorBody.error.message, 'string');
}
