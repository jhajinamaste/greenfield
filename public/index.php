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
use App\Domain\Config\ConfigService;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Inventory\InventoryCostingService;
use App\Domain\Inventory\InventoryPostingService;
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
use App\Infrastructure\Repositories\Inventory\InventoryLocationRepository;
use App\Infrastructure\Repositories\Inventory\ProductRepository;
use App\Infrastructure\Repositories\Inventory\StockLedgerRepository;
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
$configService = new ConfigService($configRepo, $appConfig['accounting_strict_mode']);
$featureFlagRepo = new FeatureFlagRepository($pdo, $scope);
$chartOfAccountRepo = new ChartOfAccountRepository($pdo, $scope);
$journalEntryRepo = new JournalEntryRepository($pdo, $scope);
$accountingPeriodRepo = new AccountingPeriodRepository($pdo, $scope);
$productRepo = new ProductRepository($pdo, $scope);
$inventoryLocationRepo = new InventoryLocationRepository($pdo, $scope);
$stockLedgerRepo = new StockLedgerRepository($pdo, $scope);
$branchRepo = new BranchRepository($pdo, $scope);
$accountingStrictMode = isset($_SESSION['organization_id'])
    ? $configService->accountingStrictMode((int) $_SESSION['organization_id'])
    : $appConfig['accounting_strict_mode'];
$auditService = new AuditLogService($auditRepo);
$authService = new AuthService($userRepo, $auditService, $tx);
$financialReportService = new FinancialReportService($journalEntryRepo, $auditService, $accountingStrictMode, $stockLedgerRepo);
$accountingPeriodGuard = new AccountingPeriodGuard($accountingPeriodRepo, $financialReportService);
$journalPostingService = new JournalPostingService(
    $tx,
    $journalEntryRepo,
    $chartOfAccountRepo,
    $accountingPeriodGuard,
    $branchRepo,
    $stockLedgerRepo,
    $auditService
);
$inventoryCostingService = new InventoryCostingService($stockLedgerRepo);
$inventoryPostingService = new InventoryPostingService(
    $tx,
    $inventoryCostingService,
    $stockLedgerRepo,
    $productRepo,
    $inventoryLocationRepo,
    $branchRepo,
    $accountingPeriodGuard,
    $accountingPeriodRepo,
    $journalPostingService,
    $chartOfAccountRepo,
    $auditService
);
$ledgerService = new LedgerService($journalEntryRepo);
$tenantService = new TenantContextService($orgRepo);
$dashboardService = new DashboardService($financialReportService, $configService);
$healthAccessMiddleware = new HealthAccessMiddleware($appConfig['health_public']);

$authController = new AuthController($authService, $tenantService);
$dashboardController = new DashboardController($dashboardService);
$healthController = new HealthController();

$router = new Router();
require __DIR__ . '/../routes/web.php';

$request = Request::capture($_SESSION);
$router->dispatch($request);
