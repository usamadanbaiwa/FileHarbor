<?php

declare(strict_types=1);

namespace FileHarbor\Security;

use FileHarbor\Config\Config;
use FileHarbor\Http\ApiError;
use FileHarbor\Http\Request;
use FileHarbor\Repositories\Registry;

final class Sessions
{
    private Registry $registry;
    private Config $config;

    public function __construct(Registry $registry, Config $config)
    {
        $this->registry = $registry;
        $this->config = $config;
    }

    public function start(string $expectedPasswordHash): string
    {
        $token = Credentials::secret();
        $csrf = Credentials::secret();
        $now = time();

        $this->registry->transaction(function () use ($token, $csrf, $now, $expectedPasswordHash): void {
            $admin = $this->registry->admin();

            ApiError::require(
                $admin !== null && $admin['password'] === $expectedPasswordHash,
                'UNAUTHORIZED',
                'Credentials changed; please log in again.',
                401
            );

            $this->registry->query('DELETE FROM sessions WHERE expires <= ?', [$now]);

            $count = (int) $this->registry->query('SELECT COUNT(*) FROM sessions')->fetchColumn();

            ApiError::require($count < 100, 'RATE_LIMIT_EXCEEDED', 'Too many active sessions.', 429);

            $this->registry->query(
                'INSERT INTO sessions (id, csrf, expires) VALUES (?, ?, ?)',
                [Credentials::digest($token), $csrf, $now + 3600]
            );
        });

        $this->cookie($token, 3600);

        return $csrf;
    }

    public function authenticate(Request $request): array
    {
        $matched = preg_match(
            '/(?:^|;\s*)fh_session=([A-Za-z0-9_-]{43})(?:;|$)/',
            $request->header('cookie'),
            $matches
        );

        ApiError::require($matched === 1, 'UNAUTHORIZED', 'Administrator login required.', 401);

        $session = $this->registry->query(
            'SELECT * FROM sessions WHERE id = ?',
            [Credentials::digest($matches[1])]
        )->fetch();

        ApiError::require(
            $session !== false && $session['expires'] > time(),
            'UNAUTHORIZED',
            'Administrator login required.',
            401
        );

        if ($request->method !== 'GET') {
            $validToken = Credentials::matches($request->header('x-csrf-token'), $session['csrf']);
            $validOrigin = $request->header('origin') === $this->config->origin;

            ApiError::require(
                $validToken && $validOrigin,
                'FORBIDDEN',
                'Invalid CSRF token or origin.',
                403
            );
        }

        return $session;
    }

    public function end(array $session): void
    {
        $this->registry->query('DELETE FROM sessions WHERE id = ?', [$session['id']]);
        $this->cookie('', 0);
    }

    public function changeCredentials(
        array $session,
        string $expectedPasswordHash,
        string $username,
        string $newPasswordHash
    ): void {
        $this->registry->transaction(function () use (
            $session,
            $expectedPasswordHash,
            $username,
            $newPasswordHash
        ): void {
            $admin = $this->registry->admin();
            $active = $this->registry->query(
                'SELECT expires FROM sessions WHERE id = ?',
                [$session['id']]
            )->fetchColumn();

            ApiError::require(
                $admin !== null &&
                $admin['password'] === $expectedPasswordHash &&
                $active !== false &&
                $active > time(),
                'UNAUTHORIZED',
                'Please log in again.',
                401
            );

            $this->registry->query(
                'UPDATE admin SET username = ?, password = ? WHERE id = 1',
                [$username, $newPasswordHash]
            );

            $this->registry->query('DELETE FROM sessions');
        });
    }

    private function cookie(string $token, int $maximumAge): void
    {
        $cookie = 'fh_session=' . $token;
        $cookie .= '; HttpOnly; SameSite=Strict; Path=/; Max-Age=' . $maximumAge;

        if ($this->config->production) {
            $cookie .= '; Secure';
        }

        header('Set-Cookie: ' . $cookie);
    }
}
