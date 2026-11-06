import path from 'node:path';
import { mkdir, lstat, readdir, unlink, rmdir, rm, link } from 'node:fs/promises';
import { ApiError, requireValue } from '../http/responses.js';
import { segments } from '../security/paths.js';

async function createDirectoryIfMissing(directory) {
    try {
        await mkdir(directory);
    } catch (error) {
        if (error.code !== 'EEXIST') {
            throw error;
        }
    }
}

function compareEntryNames(first, second) {
    if (first.name < second.name) {
        return -1;
    }

    if (first.name > second.name) {
        return 1;
    }

    return 0;
}

export class Storage {
    constructor(root) {
        this.root = root;
    }

    async resolve(id, source, options = {}) {
        const createParents = options.createParents === true;
        const allowEmpty = options.allowEmpty === true;
        const parts = [id, ...segments(source, allowEmpty)];

        let currentPath = this.root;

        for (let index = 0; index < parts.length; index += 1) {
            currentPath = path.join(currentPath, parts[index]);

            const isParent = index < parts.length - 1 || (allowEmpty && source === '');

            if (createParents && isParent) {
                await createDirectoryIfMissing(currentPath);
            }

            try {
                const entry = await lstat(currentPath);
                const safeParent = !isParent || entry.isDirectory();

                requireValue(!entry.isSymbolicLink() && safeParent, 'INVALID_PATH', 'Unsafe path.');
            } catch (error) {
                if (error.code !== 'ENOENT') {
                    throw error;
                }

                if (isParent) {
                    throw new ApiError(404, 'FILE_NOT_FOUND', 'Directory not found.');
                }
            }
        }

        return currentPath;
    }

    async put(id, source, temporaryFile, authorizeCommit) {
        const destination = await this.resolve(id, source);

        if (authorizeCommit) {
            authorizeCommit();
        }

        // A hard link commits the upload atomically without replacing an existing file.
        try {
            await link(temporaryFile, destination);
        } catch (error) {
            if (error.code === 'EEXIST') {
                throw new ApiError(409, 'FILE_EXISTS', 'The destination already exists.');
            }

            throw error;
        }
    }

    async createDirectory(id, source) {
        const destination = await this.resolve(id, source, {
            createParents: true
        });

        await createDirectoryIfMissing(destination);

        const entry = await lstat(destination);

        requireValue(
            entry.isDirectory() && !entry.isSymbolicLink(),
            'INVALID_PATH',
            'Unsafe directory.'
        );
    }

    async list(id, folder, page, limit, directoriesOnly = false) {
        const directory = await this.resolve(id, folder, {
            allowEmpty: true
        });

        const directoryEntries = await readdir(directory, {
            withFileTypes: true
        });

        const entries = [];

        for (const entry of directoryEntries) {
            if (entry.isSymbolicLink()) {
                continue;
            }

            if (directoriesOnly) {
                if (entry.isDirectory()) {
                    entries.push(entry);
                }
            } else if (entry.isFile() || entry.isDirectory()) {
                entries.push(entry);
            }
        }

        entries.sort(compareEntryNames);

        const firstIndex = (page - 1) * limit;
        const selectedEntries = entries.slice(firstIndex, firstIndex + limit);
        const items = [];

        for (const entry of selectedEntries) {
            let source = entry.name;

            if (folder) {
                source = folder + '/' + entry.name;
            }

            const fullPath = await this.resolve(id, source);
            const details = await lstat(fullPath);
            let type = 'file';

            if (entry.isDirectory()) {
                type = 'directory';
            }

            items.push({
                file_name: entry.name,
                source: source,
                type: type,
                size: details.size
            });
        }

        return {
            items: items,
            pagination: {
                page: page,
                limit: limit,
                total: entries.length
            }
        };
    }

    async remove(id, source, directory = false, recursive = false) {
        const target = await this.resolve(id, source);
        const entry = await lstat(target);
        let correctType = entry.isFile();

        if (directory) {
            correctType = entry.isDirectory();
        }

        requireValue(correctType, 'INVALID_PATH', 'The path has the wrong type.');

        if (directory) {
            if (recursive) {
                await rm(target, {
                    recursive: true,
                    force: true
                });
            } else {
                //  refuses non-empty directories.
                await rmdir(target);
            }
        } else {
            await unlink(target);
        }
    }
}
