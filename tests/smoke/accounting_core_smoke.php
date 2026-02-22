<?php

declare(strict_types=1);

use App\Core\Database\Connection;
use App\Core\Database\TransactionManager;
use App\Core\Security\ConfigCrypto;
use App\Core\Support\TenantScope;
use App\Domain\Accounting\AccountingPeriodGuard;
use App\Domain\Accounting\AccountingPeriodViolationException;
use App\Domain\Accounting\FinancialReportService;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Accounting\JournalPostingValidationException;
use App\Domain\Audit\AuditLogService;
use App\Infrastructure\Repositories\Accounting\AccountingPeriodRepository;
use App\Infrastructure\Repositories\Accounting\ChartOfAccountRepository;
use App\Infrastructure\Repositories\Accounting\JournalEntryRepository;
use App\Infrastructure\Repositories\Audit\AuditLogRepository;
use App\Infrastructure\Repositories\Tenant\BranchRepository;

require __DIR__ . '/../../bootstrap.php';

$dbConfig = require __DIR__ . '/../../config/database.php';
$appConfig = require __DIR__ . '/../../config/app.php';

try {
    $pdo = (new Connection($dbConfig))->pdo();
    $scope = new TenantScope(1, 1);
    $tx = new TransactionManager($pdo);
    $auditRepo = new AuditLogRepository($pdo, $scope);
    $auditService = new AuditLogService($auditRepo);
    $accountRepo = new ChartOfAccountRepository($pdo, $scope);
    $journalRepo = new JournalEntryRepository($pdo, $scope);
    $periodRepo = new AccountingPeriodRepository($pdo, $scope);
    $branchRepo = new BranchRepository($pdo, $scope);
    $guard = new AccountingPeriodGuard($periodRepo);
    $posting = new JournalPostingService($tx, $journalRepo, $accountRepo, $guard, $branchRepo, $auditService);
    $reports = new FinancialReportService($journalRepo);
    $crypto = new ConfigCrypto($appConfig['key']);

    $cashAccount = $accountRepo->findByCode('1000');
    $salesAccount = $accountRepo->findByCode('4000');
    if ($cashAccount === null || $salesAccount === null) {
        throw new RuntimeException('System accounts not found. Run accounting seed first.');
    }

    $date = (new DateTimeImmutable('+' . random_int(400, 900) . ' days'))->format('Y-m-d');
    $periodId = $periodRepo->createOpenPeriod($date, $date);

    $entryId = $posting->post([
        'entry_date' => $date,
        'reference_type' => 'smoke.test',
        'reference_id' => 'balanced-1',
        'description' => 'Balanced smoke posting',
        'created_by' => 1,
        'branch_id' => 1,
        'correlation_id' => bin2hex(random_bytes(12)),
        'request_id' => bin2hex(random_bytes(12)),
        'ip' => '127.0.0.1',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'accounting_core_smoke',
        'lines' => [
            ['account_id' => (int) $cashAccount['id'], 'debit_amount' => '100.0000', 'credit_amount' => '0.0000'],
            ['account_id' => (int) $salesAccount['id'], 'debit_amount' => '0.0000', 'credit_amount' => '100.0000'],
        ],
    ]);
    echo 'balanced_posting: PASS entry_id=' . $entryId . PHP_EOL;

    try {
        $posting->post([
            'entry_date' => $date,
            'reference_type' => 'smoke.test',
            'reference_id' => 'unbalanced-1',
            'description' => 'Unbalanced smoke posting',
            'created_by' => 1,
            'branch_id' => 1,
            'correlation_id' => bin2hex(random_bytes(12)),
            'request_id' => bin2hex(random_bytes(12)),
            'lines' => [
                ['account_id' => (int) $cashAccount['id'], 'debit_amount' => '100.0000', 'credit_amount' => '0.0000'],
                ['account_id' => (int) $salesAccount['id'], 'debit_amount' => '0.0000', 'credit_amount' => '99.0000'],
            ],
        ]);
        throw new RuntimeException('Unbalanced entry should have failed.');
    } catch (JournalPostingValidationException) {
        echo 'unbalanced_posting: PASS' . PHP_EOL;
    }

    $periodRepo->closePeriod($periodId, 1);
    echo 'period_close: PASS period_id=' . $periodId . PHP_EOL;

    try {
        $posting->post([
            'entry_date' => $date,
            'reference_type' => 'smoke.test',
            'reference_id' => 'closed-period-1',
            'description' => 'Should fail due to closed period',
            'created_by' => 1,
            'branch_id' => 1,
            'correlation_id' => bin2hex(random_bytes(12)),
            'request_id' => bin2hex(random_bytes(12)),
            'lines' => [
                ['account_id' => (int) $cashAccount['id'], 'debit_amount' => '10.0000', 'credit_amount' => '0.0000'],
                ['account_id' => (int) $salesAccount['id'], 'debit_amount' => '0.0000', 'credit_amount' => '10.0000'],
            ],
        ]);
        throw new RuntimeException('Closed period posting should have failed.');
    } catch (AccountingPeriodViolationException) {
        echo 'closed_period_guard: PASS' . PHP_EOL;
    }

    $trialBalance = $reports->trialBalance($date, $date);
    echo 'trial_balance_balanced: ' . ($trialBalance['is_balanced'] ? 'PASS' : 'FAIL') . PHP_EOL;

    $encrypted = $crypto->encrypt('smoke-check');
    $roundTrip = $crypto->decrypt($encrypted);
    echo 'config_crypto_roundtrip: ' . ($roundTrip === 'smoke-check' ? 'PASS' : 'FAIL') . PHP_EOL;

    $ok = $trialBalance['is_balanced'] && $roundTrip === 'smoke-check';
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Accounting core smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
