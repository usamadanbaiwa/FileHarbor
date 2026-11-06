import { generateSecret, hashSecret, secretsMatch } from './credentials.js';
import { ApiError, requireValue } from '../http/responses.js';

const sessionDuration = 60 * 60 * 1000;

export function createSessions(config) {
    const sessions = new Map();

    function createCookie(token, maximumAge) {
        let cookie = 'fh_session=' + token;
        cookie += '; HttpOnly; SameSite=Strict; Path=/; Max-Age=' + maximumAge;

        if (config.production) {
            cookie += '; Secure';
        }

        return cookie;
    }

    function removeExpiredSessions() {
        const now = Date.now();

        for (const [id, session] of sessions) {
            if (session.expires <= now) {
                sessions.delete(id);
            }
        }
    }

    function start(response) {
        removeExpiredSessions();

        requireValue(sessions.size < 100, 'RATE_LIMIT_EXCEEDED', 'Too many active sessions.', 429);

        const token = generateSecret();
        const csrfToken = generateSecret();

        sessions.set(hashSecret(token), {
            csrf: csrfToken,
            expires: Date.now() + sessionDuration
        });

        response.setHeader('Set-Cookie', createCookie(token, 3600));

        return csrfToken;
    }

    function authenticate(request, response, next) {
        const cookie = request.headers.cookie || '';
        const match = /(?:^|;\s*)fh_session=([A-Za-z0-9_-]{43})(?:;|$)/.exec(cookie);

        if (!match) {
            next(new ApiError(401, 'UNAUTHORIZED', 'Administrator login required.'));
            return;
        }

        const sessionId = hashSecret(match[1]);
        const session = sessions.get(sessionId);

        if (!session || session.expires <= Date.now()) {
            next(new ApiError(401, 'UNAUTHORIZED', 'Administrator login required.'));
            return;
        }

        request.session = session;
        request.sessionId = sessionId;

        if (request.method !== 'GET') {
            const submittedToken = request.headers['x-csrf-token'] || '';
            const validToken = secretsMatch(submittedToken, session.csrf);
            const validOrigin = request.headers.origin === config.origin;

            if (!validToken || !validOrigin) {
                next(new ApiError(403, 'FORBIDDEN', 'Invalid CSRF token or origin.'));
                return;
            }
        }

        next();
    }

    function end(request, response) {
        sessions.delete(request.sessionId);
        response.setHeader('Set-Cookie', createCookie('', 0));
    }

    function has(sessionId) {
        return sessions.has(sessionId);
    }

    function clear() {
        sessions.clear();
    }

    return {
        start: start,
        authenticate: authenticate,
        end: end,
        has: has,
        clear: clear
    };
}
