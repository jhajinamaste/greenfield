<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;

final class TenantIsolationMiddleware
{
    public function __invoke(Request $request): void
    {
        if (empty($request->session['organization_id'])) {
            http_response_code(403);
            echo 'Tenant context missing';
            exit;
        }
    }
}
