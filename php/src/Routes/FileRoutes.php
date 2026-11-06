<?php

declare(strict_types=1);

namespace FileHarbor\Routes;

use FileHarbor\Config\Config;
use FileHarbor\Http\ApiError;
use FileHarbor\Http\Request;
use FileHarbor\Http\Response;
use FileHarbor\Repositories\Registry;
use FileHarbor\Security\RateLimit;
use FileHarbor\Storage\Storage;
use FileHarbor\Storage\Upload;

final class FileRoutes
{
    private Config $config;
    private Registry $registry;
    private Storage $storage;
    private RateLimit $rateLimit;

    public function __construct(
        Config $config,
        Registry $registry,
        Storage $storage,
        RateLimit $rateLimit
    ) {
        $this->config = $config;
        $this->registry = $registry;
        $this->storage = $storage;
        $this->rateLimit = $rateLimit;
    }

    public function handle(Request $request, string $accountId, string $path): void
    {
        $this->rateLimit->check($request, 'api');

        $matched = preg_match(
            '/^Bearer ([A-Za-z0-9_-]{43})$/D',
            $request->header('authorization'),
            $matches
        );

        $account = null;

        if ($matched === 1) {
            $account = $this->registry->authorize($accountId, $matches[1]);
        }

        ApiError::require($account !== null, 'UNAUTHORIZED', 'A valid API key is required.', 401);

        if ($request->method === 'POST' && $path === '/upload') {
            $this->requirePermission($account, 'files:upload');
            $file = Upload::store($request, $this->config, $this->storage, $account);

            Response::success($file, 201);
            return;
        }

        if ($request->method === 'GET' && ($path === '/files' || $path === '/directories')) {
            $directoriesOnly = $path === '/directories';
            $permission = 'files:list';

            if ($directoriesOnly) {
                $permission = 'directories:list';
            }

            $this->requirePermission($account, $permission);
            $options = $request->listing();

            $listing = $this->storage->listing(
                $accountId,
                $options['folder'],
                $options['page'],
                $options['limit'],
                $directoriesOnly
            );

            Response::success($listing);
            return;
        }

        if ($request->method === 'POST' && $path === '/directories') {
            $this->requirePermission($account, 'directories:create');
            $input = $request->json();

            $this->storage->createDirectory($accountId, Request::field($input, 'source'));

            Response::success(['created' => true], 201);
            return;
        }

        if ($request->method === 'DELETE' && ($path === '/files' || $path === '/directories')) {
            $directory = $path === '/directories';
            $permission = 'files:delete';

            if ($directory) {
                $permission = 'directories:delete';
            }

            $this->requirePermission($account, $permission);
            $input = $request->json();

            $this->storage->remove($accountId, Request::field($input, 'source'), $directory);

            Response::success(['deleted' => true]);
            return;
        }

        throw new ApiError(404, 'NOT_FOUND', 'Endpoint not found.');
    }

    private function requirePermission(array $account, string $permission): void
    {
        ApiError::require(
            in_array($permission, $account['permissions'], true),
            'FORBIDDEN',
            'Permission denied.',
            403
        );
    }
}
