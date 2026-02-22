<?php

declare(strict_types=1);

use App\Core\Http\Middleware\AuthMiddleware;
use App\Core\Http\Middleware\BranchIsolationMiddleware;
use App\Core\Http\Middleware\CsrfMiddleware;
use App\Core\Http\Middleware\TenantIsolationMiddleware;

$router->get('/greenfield/public/login', [$authController, 'showLogin']);
$router->post('/greenfield/public/login', [$authController, 'login'], [new CsrfMiddleware()]);
$router->post('/greenfield/public/logout', [$authController, 'logout'], [new AuthMiddleware(), new CsrfMiddleware()]);

$router->get('/greenfield/public/dashboard', [$dashboardController, 'index'], [
    new AuthMiddleware(),
    new TenantIsolationMiddleware(),
    new BranchIsolationMiddleware(),
]);

$router->get('/greenfield/public/health', [$healthController, 'ping'], [$healthAccessMiddleware]);
