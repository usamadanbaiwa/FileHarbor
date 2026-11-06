<?php

declare(strict_types=1);

namespace FileHarbor\Security;

use FileHarbor\Http\ApiError;

final class Credentials
{
    public static function secret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function digest(string $value): string
    {
        return hash('sha256', $value);
    }

    public static function matches(string $first, string $second): bool
    {
        return hash_equals(self::digest($first), self::digest($second));
    }

    public static function validateLogin(mixed $username, mixed $password): void
    {
        ApiError::require(
            is_string($username) &&
            preg_match('/^[A-Za-z0-9_.@-]{3,80}$/D', $username) === 1,
            'INVALID_REQUEST',
            'Username must be 3–80 letters, digits, or . _ @ - characters.'
        );

        ApiError::require(
            is_string($password) &&
            self::length($password) >= 12 &&
            self::length($password) <= 256,
            'INVALID_REQUEST',
            'Password must be 12–256 characters.'
        );
    }

    public static function length(string $value): int
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        ApiError::require(is_array($characters));

        // Count UTF-16 code units, including two for non-BMP characters.
        $length = 0;

        foreach ($characters as $character) {
            if (strlen($character) === 4) {
                $length += 2;
            } else {
                $length += 1;
            }
        }

        return $length;
    }

    public static function hashPassword(string $password): string
    {
        // Fixed-length hexadecimal input avoids bcrypt's 72-byte password truncation.
        return password_hash(self::digest($password), PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, string $encoded): bool
    {
        return password_verify(self::digest($password), $encoded);
    }
}
