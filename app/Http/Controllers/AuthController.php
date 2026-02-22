<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfToken;
use App\Core\Security\InputSanitizer;
use App\Domain\Auth\AuthService;
use App\Domain\Tenant\TenantContextService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TenantContextService $tenantContext
    ) {
    }

    public function showLogin(Request $request): never
    {
        $csrf = CsrfToken::ensure($request->session);
        Response::view('public/views/auth/login.php', ['csrf' => $csrf]);
    }

    public function login(Request $request): never
    {
        $orgCode = InputSanitizer::clean((string) $request->input('organization_code'));
        $username = strtolower(InputSanitizer::clean((string) $request->input('username')));
        $password = (string) $request->input('password');

        $organizationId = $this->tenantContext->resolveOrganizationId($orgCode);
        if ($organizationId === null) {
            Response::json(['ok' => false, 'message' => 'Invalid organization'], 422);
        }

        $user = $this->auth->login($username, $password, $organizationId, [
            'request_id' => $request->requestId,
            'correlation_id' => $request->correlationId,
            'ip' => $request->server['REMOTE_ADDR'] ?? null,
            'ip_address' => $request->server['REMOTE_ADDR'] ?? null,
            'user_agent' => $request->server['HTTP_USER_AGENT'] ?? null,
        ]);

        if ($user === null) {
            Response::json(['ok' => false, 'message' => 'Invalid credentials'], 401);
        }

        session_regenerate_id(true);
        $request->session['user_id'] = $user['id'];
        $request->session['organization_id'] = $user['organization_id'];
        $request->session['branch_id'] = $user['branch_id'];
        $request->session['roles'] = $user['roles'];
        $request->session['last_activity'] = time();

        Response::json(['ok' => true, 'redirect' => '/greenfield/public/dashboard']);
    }

    public function logout(Request $request): never
    {
        $_SESSION = [];
        session_destroy();
        Response::redirect('/greenfield/public/login');
    }
}
