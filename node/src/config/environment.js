import path from 'node:path';
import { validateDataDirectories } from '../utils/paths.js';
import { requireValue } from '../http/responses.js';

const supportedMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
    'video/mp4'
];

function readInteger(environment, name, defaultValue, maximum) {
    let setting = defaultValue;

    if (environment[name] !== undefined && environment[name] !== null) {
        setting = environment[name];
    }

    const value = Number(setting);
    const valid = Number.isSafeInteger(value) && value > 0 && value <= maximum;

    requireValue(valid, 'CONFIG_ERROR', 'Invalid ' + name + '.');

    return value;
}

function validateOrigin(origin, production) {
    let url;

    try {
        url = new URL(origin);
    } catch {
        throw new Error('Invalid FILEHARBOR_URL.');
    }

    const validProtocol = url.protocol === 'http:' || url.protocol === 'https:';

    requireValue(
        validProtocol && url.origin === origin,
        'CONFIG_ERROR',
        'FILEHARBOR_URL must be an origin without a trailing slash.'
    );

    requireValue(
        !production || url.protocol === 'https:',
        'CONFIG_ERROR',
        'Production requires an HTTPS FILEHARBOR_URL.'
    );
}

function readMimeTypes(environment) {
    const configuredTypes = environment.ALLOWED_MIME_TYPES || supportedMimeTypes.join(',');
    const allowedTypes = configuredTypes.split(',').map(function trimType(value) {
        return value.trim();
    });

    const supported = allowedTypes.every(function isSupported(type) {
        return supportedMimeTypes.includes(type);
    });

    requireValue(
        allowedTypes.length > 0 && supported,
        'CONFIG_ERROR',
        'Allowed types are JPEG, PNG, WebP, PDF, and MP4.'
    );

    return allowedTypes;
}

export function loadConfig(environment = process.env) {
    let serveFiles = true;

    if (environment.SERVE_FILES !== undefined) {
        requireValue(
            environment.SERVE_FILES === 'true' || environment.SERVE_FILES === 'false',
            'CONFIG_ERROR',
            'SERVE_FILES must be true or false.'
        );

        serveFiles = environment.SERVE_FILES === 'true';
    }

    const storage = path.resolve(environment.STORAGE_PATH || './storage');
    const state = path.resolve(environment.FILEHARBOR_STATE_PATH || './var');

    validateDataDirectories(storage, state);

    const production = environment.FILEHARBOR_ENV !== 'development';
    const origin = environment.FILEHARBOR_URL || 'http://127.0.0.1:7241';

    validateOrigin(origin, production);

    const setupToken = environment.FILEHARBOR_SETUP_TOKEN;
    const validSetupToken = typeof setupToken === 'string' && setupToken.length >= 32;

    requireValue(
        validSetupToken,
        'CONFIG_ERROR',
        'Set FILEHARBOR_SETUP_TOKEN to a random secret of at least 32 characters.'
    );

    return {
        storage: storage,
        serveFiles: serveFiles,
        state: state,
        production: production,
        origin: origin,
        allowed: readMimeTypes(environment),
        setupToken: setupToken,
        port: readInteger(environment, 'PORT', 7241, 65535),
        host: environment.HOST || '127.0.0.1',
        maxUploads: readInteger(environment, 'MAX_CONCURRENT_UPLOADS', 4, 128),
        maxBytes: readInteger(environment, 'MAX_FILE_SIZE_MB', 25, 1024) * 1024 * 1024,
        rateLimit: readInteger(environment, 'API_RATE_LIMIT', 100, 100000),
        rateWindow: readInteger(environment, 'API_RATE_WINDOW_SECONDS', 60, 3600) * 1000
    };
}
