<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;

final class HealthController
{
    public function ping(Request $request): never
    {
        Response::json([
            'ok' => true,
            'app' => 'greenfield-erp',
            'timestamp' => gmdate('c'),
            'session_active' => !empty($request->session['user_id']),
        ]);
    }
}
