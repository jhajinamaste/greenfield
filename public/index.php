<?php

declare(strict_types=1);

use App\Core\Database\Connection;
use App\Core\Database\TransactionManager;
use App\Core\Http\GlobalExceptionHandler;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Core\Http\Middleware\HealthAccessMiddleware;
use App\Core\Security\ConfigCrypto;
use App\Core\Support\TenantScope;
use App\Domain\Accounting\AccountingPeriodGuard;
use App\Domain\Accounting\FinancialReportService;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Accounting\LedgerService;
use App\Domain\Audit\AuditLogService;
use App\Domain\Auth\AuthService;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Tenant\TenantContextService;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HealthController;
use App\Infrastructure\Repositories\Accounting\AccountingPeriodRepository;
use App\Infrastructure\Repositories\Accounting\ChartOfAccountRepository;
use App\Infrastructure\Repositories\Accounting\JournalEntryRepository;
use App\Infrastructure\Repositories\Audit\AuditLogRepository;
use App\Infrastructure\Repositories\Auth\UserRepository;
use App\Infrastructure\Repositories\Config\ConfigRepository;
use App\Infrastructure\Repositories\FeatureFlags\FeatureFlagRepository;
use App\Infrastructure\Repositories\Tenant\BranchRepository;
use App\Infrastructure\Repositories\Tenant\OrganizationRepository;

require __DIR__ . '/../bootstrap.php';

$appConfig = require __DIR__ . '/../config/app.php';
(new GlobalExceptionHandler(
    $appConfig['debug'],
    __DIR__ . '/../storage/logs/app.log'
))->register();

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => $appConfig['secure_cookies'],
    'samesite' => 'Lax',
]);
session_start();

if (!empty($_SESSION['last_activity'])) {
    $expired = (time() - (int) $_SESSION['last_activity']) > ($appConfig['session_timeout_minutes'] * 60);
    if ($expired) {
        session_destroy();
        session_start();
    }
}
$_SESSION['last_activity'] = time();

$dbConfig = require __DIR__ . '/../config/database.php';
$pdo = (new Connection($dbConfig))->pdo();
$scope = new TenantScope(
    isset($_SESSION['organization_id']) ? (int) $_SESSION['organization_id'] : null,
    isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null
);
$crypto = new ConfigCrypto($appConfig['key']);

$tx = new TransactionManager($pdo);
$userRepo = new UserRepository($pdo, $scope);
$orgRepo = new OrganizationRepository($pdo);
$auditRepo = new AuditLogRepository($pdo, $scope);
$configRepo = new ConfigRepository($pdo, $scope, $crypto);
$featureFlagRepo = new FeatureFlagRepository($pdo, $scope);
$chartOfAccountRepo = new ChartOfAccountRepository($pdo, $scope);
$journalEntryRepo = new JournalEntryRepository($pdo, $scope);
$accountingPeriodRepo = new AccountingPeriodRepository($pdo, $scope);
$branchRepo = new BranchRepository($pdo, $scope);
$auditService = new AuditLogService($auditRepo);
$authService = new AuthService($userRepo, $auditService, $tx);
$accountingPeriodGuard = new AccountingPeriodGuard($accountingPeriodRepo);
$journalPostingService = new JournalPostingService(
    $tx,
    $journalEntryRepo,
    $chartOfAccountRepo,
    $accountingPeriodGuard,
    $branchRepo,
    $auditService
);
$ledgerService = new LedgerService($journalEntryRepo);
$financialReportService = new FinancialReportService($journalEntryRepo);
$tenantService = new TenantContextService($orgRepo);
$dashboardService = new DashboardService($financialReportService);
$healthAccessMiddleware = new HealthAccessMiddleware($appConfig['health_public']);

$authController = new AuthController($authService, $tenantService);
$dashboardController = new DashboardController($dashboardService);
$healthController = new HealthController();

$router = new Router();
require __DIR__ . '/../routes/web.php';

$request = Request::capture($_SESSION);
$router->dispatch($request);
