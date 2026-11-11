import { matchesFormat, normalizeMimeType } from '../security/formats.js';
import Busboy from 'busboy';
import path from 'node:path';
import { createWriteStream } from 'node:fs';
import { mkdir, mkdtemp, rm, stat, chmod } from 'node:fs/promises';
import { pipeline } from 'node:stream/promises';
import { fileTypeFromFile } from 'file-type';
import { ApiError, requireValue } from '../http/responses.js';
import { segments } from '../security/paths.js';

function createParser(request, maximumSize) {
    const contentType = request.headers['content-type'] || '';

    requireValue(
        /^multipart\/form-data\s*;/i.test(contentType),
        'INVALID_REQUEST',
        'Expected multipart/form-data with a boundary.'
    );

    try {
        return Busboy({
            headers: request.headers,
            preservePath: true,
            limits: {
                files: 1,
                fields: 2,
                parts: 4,
                fileSize: maximumSize + 1,
                fieldSize: 1024,
                fieldNameSize: 30
            }
        });
    } catch {
        throw new ApiError(400, 'INVALID_REQUEST', 'Expected multipart/form-data with a boundary.');
    }
}

function receiveFile(parser, state, temporaryFile) {
    parser.on('file', function onFile(name, stream, info) {
        state.activeStream = stream;
        state.originalName = info.filename;

        if (name !== 'file') {
            state.error = new ApiError(
                400,
                'INVALID_REQUEST',
                'Expected one file field named file.'
            );
        }

        stream.on('limit', function onFileLimit() {
            state.error = new ApiError(413, 'FILE_TOO_LARGE', 'The file exceeds the allowed size.');
        });

        const destination = createWriteStream(temporaryFile, {
            flags: 'wx',
            mode: 0o600
        });

        state.filePromise = pipeline(stream, destination).catch(function onWriteError(error) {
            if (!state.error) {
                state.error = error;
            }

            parser.destroy(error);
        });
    });
}

function receiveFields(parser, state) {
    parser.on('field', function onField(name, value, info) {
        const supportedName = name === 'folder' || name === 'file_name';
        const repeated = Object.hasOwn(state.fields, name);
        const truncated = info.valueTruncated || info.nameTruncated;

        if (!supportedName || repeated || truncated) {
            state.error = new ApiError(400, 'INVALID_REQUEST', 'Invalid or repeated upload field.');
        }

        state.fields[name] = value;
    });

    for (const event of ['filesLimit', 'fieldsLimit', 'partsLimit']) {
        parser.on(event, function onPartLimit() {
            state.error = new ApiError(400, 'INVALID_REQUEST', 'Too many multipart fields.');
        });
    }
}

async function readMultipart(request, parser, state, maximumSize) {
    await new Promise(function parseRequest(resolve, reject) {
        let receivedBytes = 0;

        function onData(chunk) {
            receivedBytes += chunk.length;

            if (receivedBytes > maximumSize + 16384) {
                state.error = new ApiError(
                    413,
                    'FILE_TOO_LARGE',
                    'The request exceeds the allowed size.'
                );

                request.unpipe(parser);
                parser.destroy(state.error);
                request.pause();
            }
        }

        function onAbort() {
            parser.destroy(new ApiError(400, 'INVALID_REQUEST', 'Upload interrupted.'));
        }

        function onRequestError() {
            parser.destroy(new ApiError(400, 'INVALID_REQUEST', 'Upload interrupted.'));
        }

        function removeListeners() {
            request.off('data', onData);
            request.off('aborted', onAbort);
            request.off('error', onRequestError);
        }

        request.on('data', onData);
        request.once('aborted', onAbort);
        request.once('error', onRequestError);

        parser.once('error', function onParserError(error) {
            removeListeners();
            request.unpipe(parser);

            if (state.activeStream) {
                state.activeStream.destroy(error);
            }

            request.pause();

            if (error instanceof ApiError) {
                reject(error);
            } else {
                reject(new ApiError(400, 'INVALID_REQUEST', 'Malformed multipart upload.'));
            }
        });

        parser.once('close', function onParserClosed() {
            removeListeners();
            resolve();
        });

        request.pipe(parser);
    });

    if (state.filePromise) {
        await state.filePromise;
    }

    if (state.error) {
        throw state.error;
    }
}

async function inspectFile(temporaryFile, name, config) {
    let type;

    try {
        type = await fileTypeFromFile(temporaryFile);
    } catch {
        type = undefined;
    }

    let supported = false;

    if (type) {
        type.mime = normalizeMimeType(type.mime);
        supported = matchesFormat(type.mime, name, config);
    }

    requireValue(
        supported,
        'UNSUPPORTED_FILE_TYPE',
        'File content or extension is not allowed.',
        415
    );

    return type;
}

export async function upload(request, config, storage, account, authorizeCommit) {
    const uploadsDirectory = path.join(config.state, 'uploads');

    await mkdir(uploadsDirectory, {
        recursive: true,
        mode: 0o700
    });

    const temporaryDirectory = await mkdtemp(path.join(uploadsDirectory, 'upload-'));
    const temporaryFile = path.join(temporaryDirectory, 'content');

    const state = {
        fields: Object.create(null),
        originalName: null,
        activeStream: null,
        filePromise: null,
        error: null
    };

    try {
        requireValue(!request.aborted, 'INVALID_REQUEST', 'Upload interrupted.');

        const parser = createParser(request, config.maxBytes);

        receiveFile(parser, state, temporaryFile);
        receiveFields(parser, state);

        await readMultipart(request, parser, state, config.maxBytes);

        requireValue(
            state.filePromise && state.originalName,
            'INVALID_FILE',
            'A file is required.'
        );

        let name = state.originalName;

        if (state.fields.file_name !== undefined && state.fields.file_name !== null) {
            name = state.fields.file_name;
        }

        requireValue(
            segments(name).length === 1,
            'INVALID_PATH',
            'Filename must not contain directories.'
        );

        const folder = state.fields.folder || '';

        segments(folder, true);

        const type = await inspectFile(temporaryFile, name, config);
        const details = await stat(temporaryFile);

        requireValue(
            details.size <= config.maxBytes,
            'FILE_TOO_LARGE',
            'The file exceeds the allowed size.',
            413
        );

        let source = name;

        if (folder) {
            source = folder + '/' + name;
        }

        // Public assets need to be readable by the separate web server process.
        await chmod(temporaryFile, 0o644);
        await storage.put(account.id, source, temporaryFile, authorizeCommit);

        return {
            file_name: name,
            source: source,
            mime_type: type.mime,
            size: details.size
        };
    } finally {
        // Wait for the file write before removing its temporary directory.
        if (state.filePromise) {
            await state.filePromise;
        }

        await rm(temporaryDirectory, {
            recursive: true,
            force: true
        });
    }
}
