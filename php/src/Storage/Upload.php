<?php

declare(strict_types=1);

namespace FileHarbor\Storage;

use FileHarbor\Config\Config;
use FileHarbor\Http\ApiError;
use FileHarbor\Http\Request;
use FileHarbor\Security\Credentials;
use FileHarbor\Security\Formats;

use finfo;

final class Upload
{
    public static function store(
        Request $request,
        Config $config,
        Storage $storage,
        array $account
    ): array {
        self::checkRequestSize($request, $config);

        $contentType = $request->header('content-type');

        ApiError::require(
            preg_match('/^multipart\/form-data\s*;.*boundary=/i', $contentType) === 1,
            'INVALID_REQUEST',
            'Expected multipart/form-data with a boundary.'
        );

        ApiError::require(
            count($request->files) === 1 && isset($request->files['file']),
            'INVALID_FILE',
            'A file is required.'
        );

        foreach ($request->fields as $name => $value) {
            ApiError::require(
                in_array($name, ['folder', 'file_name'], true) &&
                is_string($value) &&
                strlen($value) <= 1024,
                'INVALID_REQUEST',
                'Invalid or repeated upload field.'
            );
        }

        $file = $request->files['file'];

        ApiError::require(
            isset($file['error']) && is_int($file['error']),
            'INVALID_REQUEST',
            'Expected one file field named file.'
        );

        self::checkUploadError($file['error']);

        ApiError::require(
            isset($file['tmp_name'], $file['name']) &&
            is_string($file['tmp_name']) &&
            is_string($file['name']) &&
            is_uploaded_file($file['tmp_name']),
            'INVALID_FILE',
            'A file is required.'
        );

        $name = $file['name'];

        // full_path preserves the untrusted client name that PHP may otherwise strip.
        if (isset($file['full_path'])) {
            $name = $file['full_path'];
        }

        if (array_key_exists('file_name', $request->fields)) {
            $name = $request->fields['file_name'];
        }

        ApiError::require(
            count(Storage::segments($name)) === 1,
            'INVALID_PATH',
            'Filename must not contain directories.'
        );

        $folder = '';

        if (isset($request->fields['folder']) && $request->fields['folder'] !== '') {
            $folder = $request->fields['folder'];
        }

        Storage::segments($folder, true);

        $size = filesize($file['tmp_name']);

        ApiError::require(
            $size !== false && $size <= $config->maxBytes,
            'FILE_TOO_LARGE',
            'The file exceeds the allowed size.',
            413
        );

        $inspector = new finfo(FILEINFO_MIME_TYPE);
        $mime = $inspector->file($file['tmp_name']);
        $supported = false;

        if (is_string($mime)) {
            $mime = Formats::normalize($mime);
            $supported = Formats::matches($mime, $name, $config);
        }

        ApiError::require(
            $supported,
            'UNSUPPORTED_FILE_TYPE',
            'File content or extension is not allowed.',
            415
        );

        $source = $name;

        if ($folder !== '') {
            $source = $folder . '/' . $name;
        }

        $uploads = $config->state . '/uploads';

        if (!is_dir($uploads)) {
            mkdir($uploads, 0700, true);
        }

        $temporaryDirectory = $uploads . '/upload-' . Credentials::secret();

        mkdir($temporaryDirectory, 0700);

        $temporaryFile = $temporaryDirectory . '/content';

        try {
            $moved = move_uploaded_file($file['tmp_name'], $temporaryFile);

            ApiError::require($moved, 'STORAGE_ERROR', 'The operation could not be completed.', 500);

            chmod($temporaryFile, 0644);
            $storage->put($account['id'], $source, $temporaryFile);

            return [
                'file_name' => $name,
                'source' => $source,
                'mime_type' => $mime,
                'size' => $size,
            ];
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }

            rmdir($temporaryDirectory);
        }
    }

    private static function checkRequestSize(Request $request, Config $config): void
    {
        if (isset($request->server['CONTENT_LENGTH'])) {
            $length = (int) $request->server['CONTENT_LENGTH'];

            ApiError::require(
                $length <= $config->maxBytes + 16384,
                'FILE_TOO_LARGE',
                'The request exceeds the allowed size.',
                413
            );

            $phpLimit = self::iniBytes((string) ini_get('post_max_size'));

            ApiError::require(
                $phpLimit === 0 || $length <= $phpLimit,
                'FILE_TOO_LARGE',
                'The request exceeds the allowed size.',
                413
            );
        }
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;
        $suffix = strtolower(substr($value, -1));

        if ($suffix === 'g') {
            return $number * 1024 * 1024 * 1024;
        }

        if ($suffix === 'm') {
            return $number * 1024 * 1024;
        }

        if ($suffix === 'k') {
            return $number * 1024;
        }

        return $number;
    }

    private static function checkUploadError(int $error): void
    {
        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ApiError(413, 'FILE_TOO_LARGE', 'The file exceeds the allowed size.');
        }

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new ApiError(400, 'INVALID_FILE', 'A file is required.');
        }

        if ($error === UPLOAD_ERR_PARTIAL) {
            throw new ApiError(400, 'INVALID_REQUEST', 'Upload interrupted.');
        }

        throw new ApiError(500, 'STORAGE_ERROR', 'The operation could not be completed.');
    }
}
