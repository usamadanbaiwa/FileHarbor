import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdir, readFile, readdir, stat, symlink, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { createTestServer, testImage } from './support/fixtures.js';

test('uploads, listing, pagination and exact deletion', async function fileOperations(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const account = await server.account();

    const directory = await server.call(account.route + '/directories', {
        method: 'POST',
        headers: account.headers,
        data: {
            source: 'assets/blog'
        }
    });

    assert.equal(directory.status, 201);

    const uploaded = await server.send(account, 'photo.png', testImage, {
        folder: 'assets/blog',
        file_name: 'chosen.png'
    });

    assert.equal(uploaded.status, 201);
    assert.equal(uploaded.body.data.source, 'assets/blog/chosen.png');

    const savedPath = path.join(server.config.storage, account.id, 'assets/blog/chosen.png');

    assert.deepEqual(await readFile(savedPath), testImage);

    if (process.platform !== 'win32') {
        const savedFile = await stat(savedPath);
        const privateState = await stat(server.config.state);

        assert.equal(savedFile.mode & 0o777, 0o644);
        assert.equal(privateState.mode & 0o777, 0o700);
    }

    const secondUpload = await server.send(account, 'z.png', testImage, {
        folder: 'assets/blog'
    });

    assert.equal(secondUpload.status, 201);

    const firstPage = await server.call(
        account.route + '/files?folder=assets/blog&page=1&limit=1',
        {
            headers: account.headers
        }
    );

    const secondPage = await server.call(
        account.route + '/files?folder=assets/blog&page=2&limit=1',
        {
            headers: account.headers
        }
    );

    assert.equal(firstPage.body.data.pagination.total, 2);
    assert.equal(firstPage.body.data.items[0].file_name, 'chosen.png');
    assert.equal(secondPage.body.data.items[0].file_name, 'z.png');

    const nonemptyDirectory = await server.call(account.route + '/directories', {
        method: 'DELETE',
        headers: account.headers,
        data: {
            source: 'assets/blog'
        }
    });

    assert.equal(nonemptyDirectory.status, 409);

    const deleteOptions = {
        method: 'DELETE',
        headers: account.headers,
        data: {
            source: 'assets/blog/chosen.png'
        }
    };

    const deleted = await server.call(account.route + '/files', deleteOptions);
    const alreadyDeleted = await server.call(account.route + '/files', deleteOptions);

    assert.equal(deleted.status, 200);
    assert.equal(alreadyDeleted.status, 404);
    assert.deepEqual(await readdir(path.join(server.config.state, 'uploads')), []);
});

test('traversal, unsupported content and concurrent collisions are rejected', async function unsafeUploads(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const account = await server.account();

    const unsafePaths = [
        '../outside',
        '/absolute',
        'assets/../../outside',
        'a\\b',
        'CON.png',
        'a//b',
        'folder\n',
        'folder\u2028',
        'assets/\u0000file',
        'assets/file:stream'
    ];

    for (const source of unsafePaths) {
        const result = await server.call(account.route + '/files', {
            method: 'DELETE',
            headers: account.headers,
            data: {
                source: source
            }
        });

        assert.equal(result.status, 400, source);
        assert.equal(result.body.error.code, 'INVALID_PATH');
    }

    const fakeImage = await server.send(
        account,
        'script.png',
        Buffer.from('<script>alert(1)</script>')
    );

    const unsafeExtension = await server.send(account, 'image.exe');

    assert.equal(fakeImage.status, 415);
    assert.equal(unsafeExtension.status, 415);

    const uploads = await Promise.all([server.send(account), server.send(account)]);

    const statuses = uploads.map(function getStatus(result) {
        return result.status;
    });

    assert.deepEqual(statuses.sort(), [201, 409]);

    const savedFile = await readFile(path.join(server.config.storage, account.id, 'image.png'));

    assert.deepEqual(savedFile, testImage);
    assert.deepEqual(await readdir(path.join(server.config.state, 'uploads')), []);
});

test('size limits, malformed multipart and pagination errors', async function invalidRequests(testContext) {
    const server = await createTestServer(testContext, {
        maxBytes: 1024 * 1024
    });

    await server.setup();
    await server.login();

    const account = await server.account();
    const tooLarge = await server.send(account, 'large.png', Buffer.alloc(1024 * 1024 + 1));

    const wrongContentType = await server.call(account.route + '/upload', {
        method: 'POST',
        headers: account.headers,
        data: {}
    });

    const invalidPage = await server.call(account.route + '/files?page=0', {
        headers: account.headers
    });

    assert.equal(tooLarge.status, 413);
    assert.equal(wrongContentType.status, 400);
    assert.equal(invalidPage.status, 400);

    const headers = Object.assign({}, account.headers, {
        'Content-Type': 'multipart/form-data; boundary=broken'
    });

    const malformed = await fetch(server.config.origin + account.route + '/upload', {
        method: 'POST',
        headers: headers,
        body: '--broken\r\ninvalid'
    });

    const errorBody = await malformed.text();

    assert.equal(malformed.status, 400);
    assert.ok(!errorBody.includes(server.root));
    assert.deepEqual(await readdir(path.join(server.config.state, 'uploads')), []);
});

test('symlinks cannot escape an account root', async function symlinkEscape(testContext) {
    const server = await createTestServer(testContext);

    await server.setup();
    await server.login();

    const account = await server.account();
    const outsideDirectory = path.join(server.root, 'outside');
    const outsideFile = path.join(outsideDirectory, 'keep.png');

    await mkdir(outsideDirectory);
    await writeFile(outsideFile, 'keep');

    let linkType = 'dir';

    if (process.platform === 'win32') {
        linkType = 'junction';
    }

    try {
        await symlink(
            outsideDirectory,
            path.join(server.config.storage, account.id, 'escape'),
            linkType
        );
    } catch (error) {
        if (error.code === 'EPERM' || error.code === 'EACCES') {
            testContext.skip('OS does not permit test symlinks');
            return;
        }

        throw error;
    }

    const result = await server.call(account.route + '/files', {
        method: 'DELETE',
        headers: account.headers,
        data: {
            source: 'escape/keep.png'
        }
    });

    assert.equal(result.status, 400);
    assert.equal(await readFile(outsideFile, 'utf8'), 'keep');
});
