<?php

declare(strict_types=1);

namespace FileHarbor\Routes;

use FileHarbor\Config\Config;
use FileHarbor\Http\ApiError;
use FileHarbor\Http\Request;
use FileHarbor\Http\Response;
use FileHarbor\Repositories\Registry;
use FileHarbor\Security\Credentials;
use FileHarbor\Security\Sessions;
use FileHarbor\Security\RateLimit;
use FileHarbor\Storage\Storage;

use Throwable;

final class PanelRoutes
{
    private Config $config;
    private Registry $registry;
    private Storage $storage;
    private Sessions $sessions;
    private RateLimit $rateLimit;

    public function __construct(
        Config $config,
        Registry $registry,
        Storage $storage,
        Sessions $sessions,
        RateLimit $rateLimit
    ) {
        $this->config = $config;
        $this->registry = $registry;
        $this->storage = $storage;
        $this->sessions = $sessions;
        $this->rateLimit = $rateLimit;
    }

    public function handle(Request $request, string $path): void
    {
        if ($request->method === 'GET' && $path === '/status') {
            
            Response::success(['setup_required' => $this->registry->admin() === null]);
            return;
        }

        if ($request->method === 'POST' && $path === '/setup') {
            $this->setup($request);
            return;
        }

        if ($request->method === 'POST' && $path === '/login') {
            $this->login($request);
            return;
        }

        $session = $this->sessions->authenticate($request);

        if ($request->method === 'GET' && $path === '/session') {
            Response::success(['csrf_token' => $session['csrf']]);
            return;
        }

        if ($request->method === 'POST' && $path === '/logout') {
            $this->sessions->end($session);

            Response::success(['logged_out' => true]);
            return;
        }

        if ($request->method === 'GET' && $path === '/accounts') {
            Response::success([
                'accounts' => $this->registry->accounts(),
                'permissions' => Registry::PERMISSIONS,
            ]);
            return;
        }

        if ($request->method === 'POST' && $path === '/accounts') {
            $this->createAccount($request);
            return;
        }

        if ($request->method === 'POST' && $path === '/credentials') {
            $this->changeCredentials($request, $session);
            return;
        }

        if (preg_match('#^/accounts/([^/]+)(/rotate)?$#D', $path, $matches) === 1) {
            $id = $matches[1];
            $account = $this->registry->account($id);

            ApiError::require($account !== null, 'ACCOUNT_NOT_FOUND', 'Account not found.', 404);

            $suffix = '';

            if (isset($matches[2])) {
                $suffix = $matches[2];
            }

            if ($request->method === 'POST' && $suffix === '/rotate') {
                Response::success(['api_key' => $this->registry->rotateKey($id)]);
                return;
            }

            if ($request->method === 'PATCH' && $suffix === '') {
                $input = $this->accountInput($request);
                $enabled = Request::field($input, 'enabled');

                ApiError::require(is_bool($enabled));

                $this->registry->updateAccount($id, trim($input['name']), $input['permissions'], $enabled);

                Response::success(['updated' => true]);
                return;
            }

            if ($request->method === 'DELETE' && $suffix === '') {
                $this->registry->removeAccount($id);

                Response::success(['removed' => true, 'files_retained' => true]);
                return;
            }
        }

        throw new ApiError(404, 'NOT_FOUND', 'Endpoint not found.');
    }

    private function setup(Request $request): void
    {
        $this->rateLimit->check($request, 'setup', 10);

        ApiError::require(
            $this->registry->admin() === null,
            'SETUP_COMPLETE',
            'Setup has already completed.',
            409
        );

        $input = $request->json();
        $token = Request::field($input, 'setup_token');
        $username = Request::field($input, 'username');
        $password = Request::field($input, 'password');

        ApiError::require(
            is_string($token) && Credentials::matches($token, $this->config->setupToken),
            'UNAUTHORIZED',
            'Invalid setup token.',
            401
        );

        Credentials::validateLogin($username, $password);

        $passwordHash = Credentials::hashPassword($password);

        $this->registry->createAdmin($username, $passwordHash);

        Response::success(['created' => true], 201);
    }

    private function login(Request $request): void
    {
        $this->rateLimit->check($request, 'login', 10);

        ApiError::require(
            $request->header('origin') === $this->config->origin,
            'FORBIDDEN',
            'Invalid origin.',
            403
        );

        $input = $request->json();
        $username = Request::field($input, 'username');
        $password = Request::field($input, 'password');

        ApiError::require(
            is_string($username) && is_string($password) && Credentials::length($password) <= 256
        );

        $admin = $this->registry->admin();
        $hash = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

        if ($admin !== null) {
            $hash = $admin['password'];
        }

        $validPassword = Credentials::verifyPassword($password, $hash);

        ApiError::require(
            $admin !== null &&
            Credentials::matches($username, $admin['username']) &&
            $validPassword,
            'UNAUTHORIZED',
            'Invalid credentials.',
            401
        );

        $csrf = $this->sessions->start($admin['password']);

        Response::success(['csrf_token' => $csrf]);
    }

    private function createAccount(Request $request): void
    {
        $input = $this->accountInput($request);
        $account = $this->registry->createAccount(trim($input['name']), $input['permissions']);

        try {
            $this->storage->createAccountRoot($account['id']);
        } catch (Throwable $error) {
            $this->registry->removeAccount($account['id']);
            throw $error;
        }

        Response::success($account, 201);
    }

    private function changeCredentials(Request $request, array $session): void
    {
        $this->rateLimit->check($request, 'credentials', 10);

        $input = $request->json();
        $username = Request::field($input, 'username');
        $password = Request::field($input, 'password');
        $currentPassword = Request::field($input, 'current_password');

        Credentials::validateLogin($username, $password);

        ApiError::require(is_string($currentPassword) && Credentials::length($currentPassword) <= 256);

        $admin = $this->registry->admin();

        ApiError::require(
            $admin !== null && Credentials::verifyPassword($currentPassword, $admin['password']),
            'UNAUTHORIZED',
            'Current password is incorrect.',
            401
        );

        $newPasswordHash = Credentials::hashPassword($password);

        $this->sessions->changeCredentials($session, $admin['password'], $username, $newPasswordHash);

        Response::success(['updated' => true, 'login_required' => true]);
    }

    private function accountInput(Request $request): array
    {
        $input = $request->json();
        $name = Request::field($input, 'name');
        $permissions = Request::field($input, 'permissions');

        ApiError::require(
            is_string($name) && trim($name) !== '' && Credentials::length($name) <= 80
        );

        ApiError::require(is_array($permissions), 'INVALID_REQUEST', 'Invalid permissions.');

        $seen = [];

        foreach ($permissions as $permission) {
            ApiError::require(
                is_string($permission) &&
                in_array($permission, Registry::PERMISSIONS, true) &&
                !in_array($permission, $seen, true),
                'INVALID_REQUEST',
                'Invalid permissions.'
            );

            $seen[] = $permission;
        }

        return $input;
    }
}
