<?php

declare(strict_types=1);

namespace FileHarbor;

use FileHarbor\Config\Config;
use FileHarbor\Http\ApiError;
use FileHarbor\Http\Request;
use FileHarbor\Http\Response;
use FileHarbor\Repositories\Registry;
use FileHarbor\Security\Sessions;
use FileHarbor\Security\RateLimit;
use FileHarbor\Storage\Storage;
use FileHarbor\Routes\PanelRoutes;
use FileHarbor\Routes\FileRoutes;
use FileHarbor\Routes\ContentRoutes;

final class App
{
    private PanelRoutes $panelRoutes;
    private FileRoutes $fileRoutes;
    private ?ContentRoutes $contentRoutes = null;

    public function __construct(Config $config)
    {
        Storage::prepareRoot($config->storage);
        Storage::prepareRoot($config->state);
        Storage::prepareRoot($config->state . '/uploads');

        chmod($config->state . '/uploads', 0700);

        $storageDetails = stat($config->storage);
        $stateDetails = stat($config->state);

        ApiError::require(
            $storageDetails['dev'] === $stateDetails['dev'],
            'CONFIG_ERROR',
            'Storage and private state must be on the same filesystem for atomic uploads.',
            500
        );

        $registry = new Registry($config->state);
        $storage = new Storage($config->storage);
        $sessions = new Sessions($registry, $config);
        $rateLimit = new RateLimit($registry, $config);

        $this->panelRoutes = new PanelRoutes($config, $registry, $storage, $sessions, $rateLimit);
        $this->fileRoutes = new FileRoutes($config, $registry, $storage, $rateLimit);

        if ($config->serveFiles) {
            $this->contentRoutes = new ContentRoutes($storage);
        }
    }

    public function handle(Request $request): void
    {

        if ($request->method === 'GET' && $request->path === '/api/health') {
            Response::success(['status' => 'ok']);
            return;
        }

        if ($request->method === 'GET' && $request->path === '/panel') {
            Response::headers();

            http_response_code(301);
            header('Location: /panel/');
            return;
        }

        if ($request->method === 'GET' && $request->path === '/panel/') {
            
            require dirname(__DIR__) . '/public/panel/index.php';
            return;
        }

        if (str_starts_with($request->path, '/panel-api/')) {

            $path = substr($request->path, strlen('/panel-api'));

            $this->panelRoutes->handle($request, $path);
            return;
        }

        $matched = preg_match(
            '#^/fileharbor/([^/]+)/api(/.*)?$#D',
            $request->path,
            $matches
        );

        if ($matched === 1) {
            $path = '';

            if (isset($matches[2])) {
                $path = $matches[2];
            }

            $this->fileRoutes->handle($request, $matches[1], $path);
            return;
        }

        if ($this->contentRoutes !== null && $this->contentRoutes->handle($request)) {
            return;
        }

        throw new ApiError(404, 'NOT_FOUND', 'Endpoint not found.');
    }
}
