export class ApiError extends Error {
    constructor(status, code, message) {
        super(message);

        this.status = status;
        this.code = code;
    }
}

export function requireValue(
    condition,
    code = 'INVALID_REQUEST',
    message = 'The request is invalid.',
    status = 400
) {
    if (!condition) {
        throw new ApiError(status, code, message);
    }
}

export function success(response, data, status = 200) {
    return response.status(status).json({
        success: true,
        data: data
    });
}

function getPublicError(error) {
    if (error instanceof ApiError) {
        return error;
    }

    if (error.type === 'entity.too.large') {
        return new ApiError(413, 'FILE_TOO_LARGE', 'The request exceeds the allowed size.');
    }

    if (error.type === 'entity.parse.failed') {
        return new ApiError(400, 'INVALID_REQUEST', 'Invalid JSON.');
    }

    if (error.code === 'ENOENT') {
        return new ApiError(404, 'FILE_NOT_FOUND', 'File or directory not found.');
    }

    if (error.code === 'ENOTEMPTY' || error.code === 'EEXIST') {
        return new ApiError(
            409,
            'CONFLICT',
            'The destination exists or the directory is not empty.'
        );
    }

    if (error.code === 'ENOTDIR') {
        return new ApiError(400, 'INVALID_PATH', 'Expected a directory.');
    }

    // Internal errors must not reveal filesystem paths or database details.
    return new ApiError(500, 'STORAGE_ERROR', 'The operation could not be completed.');
}

export function handleError(error, request, response, next) {
    if (response.headersSent) {
        next(error);
        return;
    }

    const publicError = getPublicError(error);

    if (!request.complete) {
        // Do not keep draining an oversized or rejected request indefinitely.
        response.setHeader('Connection', 'close');

        response.once('finish', function closeRejectedRequest() {
            request.destroy();
        });
    }

    response.status(publicError.status).json({
        success: false,
        error: {
            code: publicError.code,
            message: publicError.message
        }
    });
}
