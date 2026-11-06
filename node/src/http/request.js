import { requireValue } from './responses.js';
import { permissions } from '../repositories/registry.js';

export function readJsonBody(request) {
    const value = request.body;
    const valid = value && !Array.isArray(value) && typeof value === 'object';

    requireValue(valid, 'INVALID_REQUEST', 'Expected a JSON object.');

    return value;
}

export function readAccountInput(request) {
    const value = readJsonBody(request);

    const validName =
        typeof value.name === 'string' && value.name.trim().length > 0 && value.name.length <= 80;

    requireValue(validName);

    requireValue(Array.isArray(value.permissions), 'INVALID_REQUEST', 'Invalid permissions.');

    const supportedPermissions = value.permissions.every(function isSupported(permission) {
        return permissions.includes(permission);
    });

    const uniquePermissions = new Set(value.permissions).size === value.permissions.length;

    requireValue(
        supportedPermissions && uniquePermissions,
        'INVALID_REQUEST',
        'Invalid permissions.'
    );

    return value;
}

export function readListingOptions(request) {
    let folder = '';
    let page = 1;
    let limit = 50;

    if (request.query.folder !== undefined && request.query.folder !== null) {
        folder = request.query.folder;
    }

    if (request.query.page !== undefined && request.query.page !== null) {
        page = Number(request.query.page);
    }

    if (request.query.limit !== undefined && request.query.limit !== null) {
        limit = Number(request.query.limit);
    }

    const validPage = Number.isSafeInteger(page) && page > 0 && page <= 1000000;
    const validLimit = Number.isInteger(limit) && limit > 0 && limit <= 100;

    requireValue(validPage && validLimit);

    return {
        folder: folder,
        page: page,
        limit: limit
    };
}
