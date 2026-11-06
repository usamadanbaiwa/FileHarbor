import express from 'express';
import { ApiError, success } from '../http/responses.js';
import { readJsonBody, readListingOptions } from '../http/request.js';
import { upload } from '../storage/upload.js';
import { createWorkLimiter } from '../security/concurrency.js';

function authenticateAccount(registry) {
    return function checkApiKey(request, response, next) {
        const authorization = request.headers.authorization || '';
        const match = /^Bearer ([A-Za-z0-9_-]{43})$/.exec(authorization);
        let account = null;

        if (match) {
            account = registry.authorize(request.params.accountId, match[1]);

        }

        if (!account) {
            next(new ApiError(401, 'UNAUTHORIZED', 'A valid API key is required.'));
            return;
        }

        request.account = account;
        request.apiKey = match[1];

        next();
    };
}

function requirePermission(permission) {
    return function checkPermission(request, response, next) {

        if (!request.account.permissions.includes(permission)) {
            next(new ApiError(403, 'FORBIDDEN', 'Permission denied.'));
            return;
        }

        next();
    };
}

export function createFileRouter(config, registry, storage, limitRequests) {
    const runUpload = createWorkLimiter(config.maxUploads);

    const router = express.Router({
        mergeParams: true
    });

    router.use(limitRequests('api'));
    router.use(authenticateAccount(registry));

    router.post(
        '/upload',
        requirePermission('files:upload'),
        async function uploadFile(request, response) {
            function authorizeCommit() {
                if (request.aborted) {
                    throw new ApiError(400, 'INVALID_REQUEST', 'Upload interrupted.');
                }

                const currentAccount = registry.authorize(request.account.id, request.apiKey);

                if (!currentAccount) {
                    throw new ApiError(401, 'UNAUTHORIZED', 'A valid API key is required.');
                }

                if (!currentAccount.permissions.includes('files:upload')) {
                    throw new ApiError(403, 'FORBIDDEN', 'Permission denied.');
                }
            }

            const file = await runUpload(async function saveUpload() {
                return await upload(request, config, storage, request.account, authorizeCommit);
            });

            success(response, file, 201);
        }
    );

    router.get(
        '/files',
        requirePermission('files:list'),
        async function listFiles(request, response) {
            const options = readListingOptions(request);

            const listing = await storage.list(
                request.account.id,
                options.folder,
                options.page,
                options.limit
            );

            success(response, listing);
        }
    );

    router.delete(
        '/files',
        requirePermission('files:delete'),
        async function deleteFile(request, response) {
            const input = readJsonBody(request);

            await storage.remove(request.account.id, input.source);

            success(response, {
                deleted: true
            });
        }
    );

    router.post(
        '/directories',
        requirePermission('directories:create'),
        async function createDirectory(request, response) {
            const input = readJsonBody(request);

            await storage.createDirectory(request.account.id, input.source);

            success(
                response,
                {
                    created: true
                },
                201
            );
        }
    );

    router.get(
        '/directories',
        requirePermission('directories:list'),
        async function listDirectories(request, response) {
            const options = readListingOptions(request);

            const listing = await storage.list(
                request.account.id,
                options.folder,
                options.page,
                options.limit,
                true
            );

            success(response, listing);
        }
    );

    router.delete(
        '/directories',
        requirePermission('directories:delete'),
        async function deleteDirectory(request, response) {
            const input = readJsonBody(request);
            const source = input.source;
            const recursive = input.recursive || false;

            await storage.remove(request.account.id, source, true, recursive);

            success(response, {
                deleted: true
            });
        }
    );

    return router;
}
