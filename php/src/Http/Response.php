<?php

declare(strict_types=1);

namespace FileHarbor\Http;

use Throwable;

final class Response
{
    public static function headers(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header(
            "Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; " .
            "object-src 'none'; base-uri 'none'; form-action 'self'"
        );
    }

    public static function success(array $data, int $status = 200): void
    {
        self::json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    public static function error(Throwable $error): void
    {
        if (!$error instanceof ApiError) {
            $error = new ApiError(
                500,
                'STORAGE_ERROR',
                'The operation could not be completed.'
            );
        }

        self::json([
            'success' => false,
            'error' => [
                'code' => $error->errorCode,
                'message' => $error->getMessage(),
            ],
        ], $error->status);
    }

    private static function json(array $body, int $status): void
    {
        self::headers();

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
