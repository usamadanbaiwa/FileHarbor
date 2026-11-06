<?php

declare(strict_types=1);

namespace FileHarbor\Http;

use RuntimeException;

final class ApiError extends RuntimeException
{
    public int $status;
    public string $errorCode;

    public function __construct(int $status, string $code, string $message)
    {
        parent::__construct($message);

        $this->status = $status;
        $this->errorCode = $code;
    }

    public static function require(
        bool $condition,
        string $code = 'INVALID_REQUEST',
        string $message = 'The request is invalid.',
        int $status = 400
    ): void {
        if (!$condition) {
            throw new self($status, $code, $message);
        }
    }
}
