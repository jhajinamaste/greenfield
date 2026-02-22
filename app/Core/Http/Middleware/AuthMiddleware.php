<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;

final class AuthMiddleware
{
    public function __invoke(Request $request): void
    {
        if (empty($request->session['user_id'])) {
            header('Location: /greenfield/public/login');
            exit;
        }
    }
}
