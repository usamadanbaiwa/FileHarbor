import { ApiError, requireValue } from '../http/responses.js';

export function createWorkLimiter(maximum) {
    requireValue(
        Number.isInteger(maximum) && maximum > 0 && maximum <= 128,
        'CONFIG_ERROR',
        'Invalid concurrency limit.'
    );

    let active = 0;

    return async function runWithLimit(operation) {
        if (active >= maximum) {
            throw new ApiError(429, 'RATE_LIMIT_EXCEEDED', 'The server is busy. Try again later.');
        }

        active += 1;

        try {
            return await operation();
        } finally {
            active -= 1;
        }
    };
}
