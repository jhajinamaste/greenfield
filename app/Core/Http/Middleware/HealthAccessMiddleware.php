<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;

final class HealthAccessMiddleware
{
    public function __construct(private readonly bool $allowPublicHealth = false)
    {
    }

    public function __invoke(Request $request): void
    {
        if ($this->allowPublicHealth) {
            return;
        }

        $roles = $request->session['roles'] ?? [];
        if (!is_array($roles)) {
            $roles = [];
        }

        $isAdmin = in_array('super_admin', $roles, true) || in_array('org_admin', $roles, true);
        if ($isAdmin) {
            return;
        }

        http_response_code(403);
        echo 'Health endpoint is restricted.';
        exit;
    }
}
