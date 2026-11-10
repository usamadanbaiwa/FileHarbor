<?php

declare(strict_types=1);

namespace FileHarbor\Routes;

use FileHarbor\Http\ApiError;
use FileHarbor\Http\Request;
use FileHarbor\Http\Response;
use FileHarbor\Storage\Storage;
use finfo;

final class ContentRoutes
{
    private Storage $storage;

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    public function handle(Request $request): bool
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if (preg_match('#^/([A-Za-z0-9_-]{22})/(.+)$#D', $request->path, $matches) !== 1) {
            return false;
        }

        $filename = $this->storage->resolve($matches[1], $matches[2]);

        ApiError::require(is_file($filename), 'FILE_NOT_FOUND', 'File not found.', 404);

        $file = fopen($filename, 'rb');

        ApiError::require($file !== false, 'STORAGE_ERROR', 'The file could not be opened.', 500);

        try {
            $details = fstat($file);
            $sample = fread($file, 8192);
            $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($sample);
            $types = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4'];

            ApiError::require(in_array($type, $types, true), 'FILE_NOT_FOUND', 'File not found.', 404);

            $size = $details['size'];
            $modified = gmdate('D, d M Y H:i:s', $details['mtime']) . ' GMT';
            $etag = 'W/"' . dechex($size) . '-' . dechex($details['mtime']) . '"';

            Response::headers();
            header('Content-Type: ' . $type);
            header("Content-Security-Policy: sandbox; default-src 'none'; frame-ancestors 'none'");
            header('Cache-Control: public, max-age=0, must-revalidate');
            header('Accept-Ranges: bytes');
            header('ETag: ' . $etag);
            header('Last-Modified: ' . $modified);

            if ($this->notModified($request, $etag, $details['mtime'])) {
                http_response_code(304);
                return true;
            }

            $start = 0;
            $end = $size - 1;
            $range = $request->header('range');
            $ifRange = $request->header('if-range');

            // Weak ETags cannot satisfy If-Range. A matching date can.
            if ($ifRange !== '' && $ifRange !== $modified) {
                $range = '';
            }

            if ($request->method === 'GET' && $range !== '') {
                $selection = $this->range($range, $size);

                if ($selection === null) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    header('Content-Length: 0');
                    return true;
                }

                [$start, $end] = $selection;
                http_response_code(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            }

            $remaining = $end - $start + 1;
            header('Content-Length: ' . $remaining);

            if ($request->method === 'HEAD') {
                return true;
            }

            fseek($file, $start);

            // Release output buffers so a video does not accumulate in PHP memory.
            while (ob_get_level() > 0) {
                if (!ob_end_flush()) {
                    break;
                }
            }

            while ($remaining > 0 && !connection_aborted()) {
                $chunk = fread($file, min(65536, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }

            return true;
        } finally {
            fclose($file);
        }
    }

    private function notModified(Request $request, string $etag, int $modified): bool
    {
        $condition = $request->header('if-none-match');

        if ($condition !== '') {
            $currentTag = substr($etag, 2);

            // Some hosting compression filters append -gzip after PHP generates the ETag.
            $gzipTag = substr($currentTag, 0, -1) . '-gzip"';

            foreach (explode(',', $condition) as $candidate) {
                $candidate = trim($candidate);
                $candidateTag = preg_replace('/^W\//', '', $candidate);

                if ($candidate === '*' || $candidateTag === $currentTag || $candidateTag === $gzipTag) {
                    return true;
                }
            }

            return false;
        }

        $date = strtotime($request->header('if-modified-since'));

        return $date !== false && $date >= $modified;
    }

    private function range(string $header, int $size): ?array
    {
        if (preg_match('/^bytes=([0-9]*)-([0-9]*)$/D', $header, $matches) !== 1 || $size === 0) {
            return null;
        }

        if ($matches[1] === '' && $matches[2] === '') {
            return null;
        }

        if ($matches[1] === '') {
            $length = (int) $matches[2];

            if ($length === 0) {
                return null;
            }

            return [max(0, $size - $length), $size - 1];
        }

        $start = (int) $matches[1];
        $end = $size - 1;

        if ($matches[2] !== '') {
            $end = min($end, (int) $matches[2]);
        }

        if ($start >= $size || $start > $end) {
            return null;
        }

        return [$start, $end];
    }
}
