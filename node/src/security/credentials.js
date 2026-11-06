import { randomBytes, scrypt as scryptCallback, timingSafeEqual, createHash } from 'node:crypto';
import { promisify } from 'node:util';
import { requireValue } from '../http/responses.js';
import { createWorkLimiter } from './concurrency.js';

const scrypt = promisify(scryptCallback);
const runPasswordWork = createWorkLimiter(4);

export const dummyPasswordHash = '0'.repeat(32) + ':' + '0'.repeat(128);

export function generateSecret() {
    return randomBytes(32).toString('base64url');
}

export function hashSecret(value) {
    return createHash('sha256').update(value).digest('hex');
}

export function secretsMatch(first, second) {
    const firstHash = Buffer.from(hashSecret(first));
    const secondHash = Buffer.from(hashSecret(second));

    return timingSafeEqual(firstHash, secondHash);
}

export function validateLogin(username, password) {
    const validUsername =
        typeof username === 'string' &&
        username.length >= 3 &&
        username.length <= 80 &&
        !/[^A-Za-z0-9_.@-]/.test(username);

    requireValue(
        validUsername,
        'INVALID_REQUEST',
        'Username must be 3-80 letters, digits, or . _ @ - characters.'
    );

    const validPassword =
        typeof password === 'string' && password.length >= 12 && password.length <= 256;

    requireValue(validPassword, 'INVALID_REQUEST', 'Password must be 12–256 characters.');
}

export async function hashPassword(password) {
    const salt = randomBytes(16).toString('hex');
    const passwordHash = await runPasswordWork(async function deriveHash() {
        return await scrypt(password, salt, 64);
    });

    return salt + ':' + passwordHash.toString('hex');
}

export async function verifyPassword(password, encodedPassword) {
    const validFormat =
        typeof encodedPassword === 'string' &&
        encodedPassword.length === 161 &&
        /^[a-f0-9]{32}:[a-f0-9]{128}$/.test(encodedPassword);

    if (!validFormat) {
        encodedPassword = dummyPasswordHash;
    }

    const [salt, expectedHash] = encodedPassword.split(':');
    const actualHash = await runPasswordWork(async function deriveHash() {
        return await scrypt(password, salt, 64);
    });

    return timingSafeEqual(actualHash, Buffer.from(expectedHash, 'hex')) && validFormat;
}
