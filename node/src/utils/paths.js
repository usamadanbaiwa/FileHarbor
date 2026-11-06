import path from 'node:path';
import { mkdir, realpath, lstat } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { requireValue } from '../http/responses.js';

function comparablePath(value) {
    let normalized = path.resolve(value);

    if (process.platform === 'win32') {
        normalized = normalized.toLowerCase();
    }

    return normalized;
}

export function directoriesOverlap(first, second) {
    const parent = comparablePath(first);
    const child = comparablePath(second);
    const relative = path.relative(parent, child);

    return (
        relative === '' ||
        (!relative.startsWith('..' + path.sep) && relative !== '..' && !path.isAbsolute(relative))
    );
}

export function validateDataDirectories(storage, state) {
    const publicDirectory = fileURLToPath(new URL('../../public/', import.meta.url));
    const sourceDirectory = fileURLToPath(new URL('../', import.meta.url));

    requireValue(
        !directoriesOverlap(storage, state) && !directoriesOverlap(state, storage),
        'CONFIG_ERROR',
        'Storage and private state directories must be separate.'
    );

    for (const directory of [storage, state]) {
        requireValue(
            !directoriesOverlap(directory, publicDirectory) &&
                !directoriesOverlap(publicDirectory, directory) &&
                !directoriesOverlap(directory, sourceDirectory) &&
                !directoriesOverlap(sourceDirectory, directory),
            'CONFIG_ERROR',
            'Data directories must be outside the application source and public directories.'
        );
    }
}

export async function prepareRoot(root, mode = 0o755) {
    await mkdir(root, {
        recursive: true,
        mode: mode
    });

    const resolved = await realpath(root);

    requireValue(
        comparablePath(root) === comparablePath(resolved),
        'CONFIG_ERROR',
        'Storage must be a canonical directory without symlinks.'
    );
}

export async function rejectLinkedFile(filename) {
    try {
        const entry = await lstat(filename);

        requireValue(
            entry.isFile() && !entry.isSymbolicLink(),
            'CONFIG_ERROR',
            'Private database files must be regular files.'
        );
    } catch (error) {
        if (error.code !== 'ENOENT') {
            throw error;
        }
    }
}
