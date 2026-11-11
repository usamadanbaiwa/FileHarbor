import express from 'express';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { stat, chmod } from 'node:fs/promises';
import { ApiError, requireValue, success, handleError } from './http/responses.js';
import { openRegistry } from './repositories/registry.js';
import { Storage } from './storage/local.js';
import { createSessions } from './security/sessions.js';
import { createRateLimiter } from './security/rate-limit.js';
import { createPanelRouter } from './routes/panel.js';
import { createFileRouter } from './routes/files.js';
import { createContentHandler } from './routes/content.js';
import { prepareRoot, validateDataDirectories } from './utils/paths.js';

async function prepareDirectories(config) {
    validateDataDirectories(config.storage, config.state);

    await prepareRoot(config.storage);
    await prepareRoot(config.state, 0o700);
    await chmod(config.state, 0o700);

    const uploadsDirectory = path.join(config.state, 'uploads');

    await prepareRoot(uploadsDirectory, 0o700);
    await chmod(uploadsDirectory, 0o700);

    const storageDirectory = await stat(config.storage);
    const stateDirectory = await stat(config.state);

    requireValue(
        storageDirectory.dev === stateDirectory.dev,
        'CONFIG_ERROR',
        'Storage and private state must be on the same filesystem for atomic uploads.'
    );
}

function setResponseHeaders(request, response, next) {
    response.set({
        'X-Content-Type-Options': 'nosniff',
        'Cache-Control': 'no-store',
        'Referrer-Policy': 'no-referrer',
        'Content-Security-Policy':
            "default-src 'self'; frame-ancestors 'none'; object-src 'none'; base-uri 'none'; form-action 'self'"
    });

    next();
}

export async function createApp(config) {
    await prepareDirectories(config);

    const registry = await openRegistry(config.state);
    const storage = new Storage(config.storage);
    const sessions = createSessions(config);
    const limitRequests = createRateLimiter(config);
    const app = express();

    const panelUrl = new URL('../public/panel/', import.meta.url);
    const panelDirectory = fileURLToPath(panelUrl);

    app.disable('x-powered-by');
    app.use(setResponseHeaders);

    // Apply a cheap limit before JSON parsing, including failed authentication attempts.
    app.use(limitRequests('requests'));

    app.use(
        express.json({
            limit: '16kb',
            strict: true
        })
    );

    app.get('/api/health', function getHealth(request, response) {
        success(response, {
            status: 'ok'
        });
    });

    app.use(
        '/panel',
        express.static(panelDirectory, {
            index: 'index.html',
            dotfiles: 'deny'
        })
    );

    const panelRouter = createPanelRouter(config, registry, sessions, limitRequests);
    const fileRouter = createFileRouter(config, registry, storage, limitRequests);

    app.use('/panel-api', panelRouter);
    app.use('/fileharbor/:accountId/api', fileRouter);

    if (config.serveFiles) {
        app.use(createContentHandler(storage, config));
    }

    app.use(function handleMissingRoute(request, response, next) {
        next(new ApiError(404, 'NOT_FOUND', 'Endpoint not found.'));
    });

    app.use(handleError);

    function close() {
        sessions.clear();
        registry.db.close();
    }

    return {
        app: app,
        registry: registry,
        close: close
    };
}
