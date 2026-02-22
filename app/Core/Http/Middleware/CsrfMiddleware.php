<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;

final class CsrfMiddleware
{
    public function __invoke(Request $request): void
    {
        if ($request->method !== 'POST') {
            return;
        }

        $token = $request->isAjax()
            ? $request->header('X-CSRF-TOKEN')
            : ($request->input('_csrf') ?? $request->header('X-CSRF-TOKEN'));

        $valid = isset($request->session['_csrf']) && is_string($token) && hash_equals($request->session['_csrf'], $token);

        if (!$valid) {
            http_response_code(419);
            echo 'Invalid CSRF token';
            exit;
        }
    }
}
