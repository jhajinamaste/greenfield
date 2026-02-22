<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfToken;
use App\Domain\Dashboard\DashboardService;

final class DashboardController
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function index(Request $request): never
    {
        $summary = $this->dashboard->summary();
        $csrf = CsrfToken::ensure($request->session);

        Response::view('public/views/dashboard/index.php', [
            'summary' => $summary,
            'csrf' => $csrf,
            'user' => [
                'id' => $request->session['user_id'] ?? null,
                'roles' => $request->session['roles'] ?? [],
            ],
        ]);
    }
}
