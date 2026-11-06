import { ApiError } from '../http/responses.js';

export function createRateLimiter(config) {
    const requests = new Map();

    function removeExpiredEntries(now) {
        for (const [key, entry] of requests) {
            if (entry.expires <= now) {
                requests.delete(key);
            }
        }
    }

    return function limitRequests(group, maximum = config.rateLimit) {
        return function checkRateLimit(request, response, next) {
            const now = Date.now();

            removeExpiredEntries(now);

            const key = group + ':' + request.socket.remoteAddress;

            // Bound the registry too, so new source addresses cannot grow it indefinitely.
            if (!requests.has(key) && requests.size >= 10000) {
                next(new ApiError(429, 'RATE_LIMIT_EXCEEDED', 'Too many requests.'));
                return;
            }

            let entry = requests.get(key);

            if (!entry) {
                entry = {
                    count: 0,
                    expires: now + config.rateWindow
                };

                requests.set(key, entry);
            }

            entry.count += 1;

            if (entry.count > maximum) {
                next(new ApiError(429, 'RATE_LIMIT_EXCEEDED', 'Too many requests.'));
                return;
            }

            next();
        };
    };
}
