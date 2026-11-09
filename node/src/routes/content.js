import { fileTypeFromFile } from 'file-type';
import { stat } from 'node:fs/promises';
import { ApiError } from '../http/responses.js';

const publicTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4'];

export function createContentHandler(storage) {
    return async function serveContent(request, response, next) {
        const match = /^\/([A-Za-z0-9_-]{22})\/(.+)$/.exec(request.path);

        if (!match || (request.method !== 'GET' && request.method !== 'HEAD')) {
            next();
            return;
        }

        const accountId = match[1];
        let source;

        try {
            source = decodeURIComponent(match[2]);
        } catch {
            throw new ApiError(400, 'INVALID_PATH', 'Invalid relative path.');
        }

        const filename = await storage.resolve(accountId, source);
        const details = await stat(filename);

        if (!details.isFile()) {
            throw new ApiError(404, 'FILE_NOT_FOUND', 'File not found.');
        }

        let type;

        try {
            type = await fileTypeFromFile(filename);
        } catch {
            throw new ApiError(404, 'FILE_NOT_FOUND', 'File not found.');
        }

        if (!type || !publicTypes.includes(type.mime)) {
            throw new ApiError(404, 'FILE_NOT_FOUND', 'File not found.');
        }

        response.set({
            'Content-Type': type.mime,
            'Content-Security-Policy': "sandbox; default-src 'none'; frame-ancestors 'none'",
            'Cache-Control': 'public, max-age=0, must-revalidate'
        });

        response.sendFile(
            accountId + '/' + source,
            { root: storage.root, cacheControl: false },
            function finish(error) {
                if (error && error.status === 416) {
                    response.set('Content-Range', 'bytes */' + details.size);
                    response.status(416).end();
                    return;
                }

                if (error) {
                    next(error);
                }
            }
        );
    };
}
