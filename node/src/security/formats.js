import { requireValue } from '../http/responses.js';

export const defaultMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
    'video/mp4'
];

const builtInFormats = {
    'image/jpeg': ['jpg', 'jpeg'],
    'image/png': ['png'],
    'image/webp': ['webp'],
    'application/pdf': ['pdf'],
    'video/mp4': ['mp4'],
    'image/gif': ['gif'],
    'audio/mpeg': ['mp3'],
    'audio/wav': ['wav'],
    'application/zip': ['zip'],
    'application/gzip': ['gz']
};

const inlineTypes = [...defaultMimeTypes, 'image/gif', 'audio/mpeg', 'audio/wav'];
const blockedExtensions =
    /^(php[0-9]*|phtml|phar|html?|xhtml|svgz?|js|mjs|cjs|exe|dll|com|bat|cmd|ps1|sh|py|rb|pl|cgi|asp|aspx|jsp)$/;

export function normalizeMimeType(type) {
    const aliases = {
        'audio/x-wav': 'audio/wav',
        'audio/vnd.wave': 'audio/wav',
        'application/x-gzip': 'application/gzip',
        'application/x-zip-compressed': 'application/zip'
    };

    if (Object.hasOwn(aliases, type)) {
        return aliases[type];
    }

    return type;
}

function validMimeType(type) {
    return (
        typeof type === 'string' &&
        /^(image|audio|video|application)\/[a-z0-9][a-z0-9.+-]*$/.test(type) &&
        ![
            'image/svg+xml',
            'application/octet-stream',
            'application/javascript',
            'application/x-httpd-php',
            'application/x-executable',
            'application/x-sharedlib',
            'application/x-dosexec',
            'application/x-msdownload',
            'application/wasm',
            'application/json',
            'application/xml',
            'application/xhtml+xml'
        ].includes(type)
    );
}

export function readFormats(input) {
    let custom = {};

    if (input !== undefined) {
        requireValue(
            typeof input === 'string',
            'CONFIG_ERROR',
            'MIME_TYPE_EXTENSIONS must be a JSON object.'
        );

        try {
            custom = JSON.parse(input);
        } catch {
            requireValue(false, 'CONFIG_ERROR', 'MIME_TYPE_EXTENSIONS must be a JSON object.');
        }
    }

    requireValue(
        custom !== null && typeof custom === 'object' && !Array.isArray(custom),
        'CONFIG_ERROR',
        'MIME_TYPE_EXTENSIONS must be a JSON object.'
    );
    requireValue(Object.keys(custom).length <= 64, 'CONFIG_ERROR', 'Too many custom file formats.');

    const formats = { ...builtInFormats };

    for (const [configuredType, extensions] of Object.entries(custom)) {
        requireValue(
            validMimeType(configuredType),
            'CONFIG_ERROR',
            'Invalid or unsafe MIME type: ' + configuredType
        );
        requireValue(
            Array.isArray(extensions) && extensions.length > 0 && extensions.length <= 16,
            'CONFIG_ERROR',
            'Each MIME type needs an array of filename extensions.'
        );

        for (const extension of extensions) {
            requireValue(
                typeof extension === 'string' &&
                    /^[a-z0-9]{1,12}$/.test(extension) &&
                    !blockedExtensions.test(extension),
                'CONFIG_ERROR',
                'Invalid or unsafe filename extension.'
            );
        }

        formats[normalizeMimeType(configuredType)] = [...new Set(extensions)];
    }

    return formats;
}

export function readAllowedTypes(input, formats) {
    let configured = defaultMimeTypes.join(',');

    if (input !== undefined) {
        configured = input;
    }

    requireValue(
        typeof configured === 'string',
        'CONFIG_ERROR',
        'ALLOWED_MIME_TYPES must be a comma-separated string.'
    );

    const allowed = configured.split(',').map(function normalize(value) {
        return normalizeMimeType(value.trim());
    });

    for (const type of allowed) {
        requireValue(
            validMimeType(type) && Object.hasOwn(formats, type),
            'CONFIG_ERROR',
            'Allowed types must have a registered MIME_TYPE_EXTENSIONS entry: ' + type
        );
    }

    return [...new Set(allowed)];
}

export function matchesFormat(type, filename, config) {
    const extension = filename.split('.').pop().toLowerCase();

    return (
        config.allowed.includes(type) &&
        Object.hasOwn(config.formats, type) &&
        config.formats[type].includes(extension)
    );
}

export function servesInline(type) {
    return inlineTypes.includes(type);
}
