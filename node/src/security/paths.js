import { requireValue } from '../http/responses.js';

function isSafeSegment(segment) {
    const validLength = segment.length > 0 && segment.length <= 120;
    const validStart = /^[A-Za-z0-9]/.test(segment);
    const validCharacters = !/[^A-Za-z0-9._-]/.test(segment);
    const reservedName = /^(con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i.test(segment);

    return validLength && validStart && validCharacters && !segment.endsWith('.') && !reservedName;
}

export function segments(source, allowEmpty = false) {
    requireValue(
        typeof source === 'string' && source.length <= 1024,
        'INVALID_PATH',
        'Invalid relative path.'
    );

    if (allowEmpty && source === '') {
        return [];
    }

    const parts = source.split('/');

    requireValue(
        parts.every(isSafeSegment),
        'INVALID_PATH',
        'Use relative paths with safe filename characters.'
    );

    return parts;
}
