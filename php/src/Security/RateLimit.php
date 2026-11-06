<?php

declare(strict_types=1);

namespace FileHarbor\Security;

use FileHarbor\Config\Config;
use FileHarbor\Http\ApiError;
use FileHarbor\Http\Request;
use FileHarbor\Repositories\Registry;

final class RateLimit
{
    private Registry $registry;
    private Config $config;

    public function __construct(Registry $registry, Config $config)
    {
        $this->registry = $registry;
        $this->config = $config;
    }

    public function check(Request $request, string $group, ?int $maximum = null): void
    {
        if ($maximum === null) {
            $maximum = $this->config->rateLimit;
        }

        $address = '';

        if (isset($request->server['REMOTE_ADDR'])) {
            $address = $request->server['REMOTE_ADDR'];
        }

        $key = $group . ':' . $address;
        $now = time();

        $allowed = $this->registry->transaction(function () use ($key, $now, $maximum): bool {
            $this->registry->query('DELETE FROM rate_limits WHERE expires <= ?', [$now]);

            $entry = $this->registry->query(
                'SELECT * FROM rate_limits WHERE id = ?',
                [$key]
            )->fetch();

            if ($entry === false) {
                $count = (int) $this->registry->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn();

                if ($count >= 10000) {
                    return false;
                }

                $this->registry->query(
                    'INSERT INTO rate_limits (id, count, expires) VALUES (?, 1, ?)',
                    [$key, $now + $this->config->rateWindow]
                );

                return true;
            }

            $this->registry->query('UPDATE rate_limits SET count = count + 1 WHERE id = ?', [$key]);

            return (int) $entry['count'] + 1 <= $maximum;
        });

        ApiError::require($allowed, 'RATE_LIMIT_EXCEEDED', 'Too many requests.', 429);
    }
}
