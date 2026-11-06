import express from 'express';
import path from 'node:path';
import { mkdir } from 'node:fs/promises';
import { ApiError, requireValue, success } from '../http/responses.js';
import {
    secretsMatch,
    validateLogin,
    hashPassword,
    verifyPassword,
    dummyPasswordHash
} from '../security/credentials.js';
import { permissions } from '../repositories/registry.js';
import { readJsonBody, readAccountInput } from '../http/request.js';

function requireAccount(registry) {
    return function checkAccount(request, response, next) {
        const account = registry.get(request.params.id);

        if (!account) {
            next(new ApiError(404, 'ACCOUNT_NOT_FOUND', 'Account not found.'));
            return;
        }

        next();
    };
}

export function createPanelRouter(config, registry, sessions, limitRequests) {
    const router = express.Router();

    router.get('/status', function getSetupStatus(request, response) {
        success(response, {
            setup_required: !registry.admin()
        });
    });

    router.post('/setup', limitRequests('setup', 10), async function setup(request, response) {
        requireValue(!registry.admin(), 'SETUP_COMPLETE', 'Setup has already completed.', 409);

        const input = readJsonBody(request);

        const validToken =
            typeof input.setup_token === 'string' &&
            secretsMatch(input.setup_token, config.setupToken);

        requireValue(validToken, 'UNAUTHORIZED', 'Invalid setup token.', 401);
        validateLogin(input.username, input.password);

        const passwordHash = await hashPassword(input.password);

        registry.createAdmin(input.username, passwordHash);

        success(
            response,
            {
                created: true
            },
            201
        );
    });

    router.post('/login', limitRequests('login', 10), async function login(request, response) {
        requireValue(request.headers.origin === config.origin, 'FORBIDDEN', 'Invalid origin.', 403);

        const input = readJsonBody(request);
        const validInput =
            typeof input.username === 'string' &&
            typeof input.password === 'string' &&
            input.password.length <= 256;

        requireValue(validInput);

        const admin = registry.admin();

        // Verify a dummy hash too, so unknown users do not take a faster path.
        let passwordHash = dummyPasswordHash;

        if (admin) {
            passwordHash = admin.password;
        }

        const validPassword = await verifyPassword(input.password, passwordHash);
        const validLogin = admin && secretsMatch(input.username, admin.username) && validPassword;

        requireValue(validLogin, 'UNAUTHORIZED', 'Invalid credentials.', 401);

        const currentAdmin = registry.admin();

        requireValue(
            currentAdmin && currentAdmin.password === admin.password,
            'UNAUTHORIZED',
            'Credentials changed; please log in again.',
            401
        );

        const csrfToken = sessions.start(response);

        success(response, {
            csrf_token: csrfToken
        });
    });

    router.use(sessions.authenticate);

    router.get('/session', function getSession(request, response) {
        success(response, {
            csrf_token: request.session.csrf
        });
    });

    router.post('/logout', function logout(request, response) {
        sessions.end(request, response);

        success(response, {
            logged_out: true
        });
    });

    router.get('/accounts', function listAccounts(request, response) {
        success(response, {
            accounts: registry.list(),
            permissions: permissions
        });
    });

    router.post('/accounts', async function createAccount(request, response) {
        const input = readAccountInput(request);
        const account = registry.create(input.name.trim(), input.permissions);
        const accountDirectory = path.join(config.storage, account.id);

        try {
            await mkdir(accountDirectory);
        } catch (error) {
            registry.remove(account.id);
            throw error;
        }

        success(response, account, 201);
    });

    router.use('/accounts/:id', requireAccount(registry));

    router.patch('/accounts/:id', function updateAccount(request, response) {
        const input = readAccountInput(request);

        requireValue(typeof input.enabled === 'boolean');

        registry.update(request.params.id, input.name.trim(), input.permissions, input.enabled);

        success(response, {
            updated: true
        });
    });

    router.post('/accounts/:id/rotate', function rotateKey(request, response) {
        const key = registry.rotate(request.params.id);

        success(response, {
            api_key: key
        });
    });

    router.delete('/accounts/:id', function removeAccount(request, response) {
        registry.remove(request.params.id);

        success(response, {
            removed: true,
            files_retained: true
        });
    });

    router.post(
        '/credentials',
        limitRequests('credentials', 10),
        async function changeCredentials(request, response) {
            const input = readJsonBody(request);

            validateLogin(input.username, input.password);

            requireValue(
                typeof input.current_password === 'string' && input.current_password.length <= 256
            );

            const admin = registry.admin();
            const validPassword = await verifyPassword(input.current_password, admin.password);

            requireValue(validPassword, 'UNAUTHORIZED', 'Current password is incorrect.', 401);

            const passwordHash = await hashPassword(input.password);
            const currentAdmin = registry.admin();

            // Recheck after hashing in case another request changed the credentials.
            const sessionStillValid =
                currentAdmin.password === admin.password && sessions.has(request.sessionId);

            requireValue(sessionStillValid, 'UNAUTHORIZED', 'Please log in again.', 401);

            registry.updateAdmin(input.username, passwordHash);
            sessions.clear();

            success(response, {
                updated: true,
                login_required: true
            });
        }
    );

    return router;
}
