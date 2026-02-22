<?php

declare(strict_types=1);

use App\Core\Database\Connection;
use App\Core\Database\TransactionManager;
use App\Core\Security\ConfigCrypto;
use App\Core\Support\TenantScope;
use App\Domain\Accounting\AccountingPeriodGuard;
use App\Domain\Accounting\AccountingPeriodViolationException;
use App\Domain\Accounting\FinancialReportService;
use App\Domain\Accounting\InvariantViolationException;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Accounting\JournalPostingValidationException;
use App\Domain\Audit\AuditLogService;
use App\Infrastructure\Repositories\Accounting\AccountingPeriodRepository;
use App\Infrastructure\Repositories\Accounting\ChartOfAccountRepository;
use App\Infrastructure\Repositories\Accounting\JournalEntryRepository;
use App\Infrastructure\Repositories\Audit\AuditLogRepository;
use App\Infrastructure\Repositories\Inventory\StockLedgerRepository;
use App\Infrastructure\Repositories\Tenant\BranchRepository;

require __DIR__ . '/../../bootstrap.php';

/**
 * @param class-string<Throwable> $class
 */
function expectStructuredException(callable $fn, string $class, string $expectedCode, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            throw new RuntimeException($label . ': unexpected exception class ' . get_class($e));
        }

        if (!method_exists($e, 'details')) {
            throw new RuntimeException($label . ': exception missing details() payload');
        }

        /** @var array<string, mixed> $details */
        $details = $e->details();
        if (($details['code'] ?? null) !== $expectedCode) {
            throw new RuntimeException($label . ': expected code ' . $expectedCode . ', got ' . ($details['code'] ?? 'null'));
        }

        echo $label . ': PASS code=' . $expectedCode . PHP_EOL;
        return;
    }

    throw new RuntimeException($label . ': expected exception was not thrown');
}

function nextAvailablePeriodDate(PDO $pdo, int $organizationId): string
{
    $stmt = $pdo->prepare('SELECT MAX(period_end) AS max_period_end FROM accounting_periods WHERE organization_id = :organization_id');
    $stmt->execute([':organization_id' => $organizationId]);
    $maxEnd = (string) ($stmt->fetchColumn() ?: '');

    if ($maxEnd === '') {
        return (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
    }

    $next = (new DateTimeImmutable($maxEnd))->modify('+1 day');
    if ($next->format('Y-m-d') > '9999-12-31') {
        throw new RuntimeException('No available accounting period date remains for smoke isolation.');
    }

    return $next->format('Y-m-d');
}

function firstOpenPeriodDate(PDO $pdo, int $organizationId): ?string
{
    $stmt = $pdo->prepare('SELECT period_start FROM accounting_periods WHERE organization_id = :organization_id AND status = :status ORDER BY period_start ASC LIMIT 1');
    $stmt->execute([
        ':organization_id' => $organizationId,
        ':status' => 'open',
    ]);

    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

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
    $stockLedgerRepo = new StockLedgerRepository($pdo, $scope);
    $reports = new FinancialReportService($journalRepo, $auditService, true);
    $guard = new AccountingPeriodGuard($periodRepo, $reports);
    $posting = new JournalPostingService($tx, $journalRepo, $accountRepo, $guard, $branchRepo, $stockLedgerRepo, $auditService);
    $crypto = new ConfigCrypto($appConfig['key']);

    $cashAccount = $accountRepo->findByCode('1000');
    $salesAccount = $accountRepo->findByCode('4000');
    if ($cashAccount === null || $salesAccount === null) {
        throw new RuntimeException('System accounts not found. Run accounting seed first.');
    }

    $organizationId = 1;
    $openPeriodDate = firstOpenPeriodDate($pdo, $organizationId);
    if ($openPeriodDate === null) {
        $openPeriodDate = nextAvailablePeriodDate($pdo, $organizationId);
        $periodRepo->createOpenPeriod($openPeriodDate, $openPeriodDate);
    }
    $date = $openPeriodDate;

    echo 'period_ready: PASS entry_date=' . $date . PHP_EOL;

    $entryId = $posting->post([
        'entry_date' => $date,
        'reference_type' => 'smoke.test',
        'reference_id' => 'balanced-1',
        'description' => 'Balanced smoke posting',
        'created_by' => 1,
        'branch_id' => 1,
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

    expectStructuredException(
        static function () use ($posting, $date, $cashAccount, $salesAccount): void {
            $posting->post([
                'entry_date' => $date,
                'reference_type' => 'smoke.test',
                'reference_id' => 'negative-1',
                'description' => 'Negative debit should fail',
                'created_by' => 1,
                'branch_id' => 1,
                'lines' => [
                    ['account_id' => (int) $cashAccount['id'], 'debit_amount' => '-10.0000', 'credit_amount' => '0.0000'],
                    ['account_id' => (int) $salesAccount['id'], 'debit_amount' => '0.0000', 'credit_amount' => '10.0000'],
                ],
            ]);
        },
        JournalPostingValidationException::class,
        'negative_amount',
        'negative_debit_rejected'
    );

    expectStructuredException(
        static function () use ($posting, $date): void {
            $posting->post([
                'entry_date' => $date,
                'reference_type' => 'smoke.test',
                'reference_id' => 'zero-lines-1',
                'description' => 'Zero line posting should fail',
                'created_by' => 1,
                'branch_id' => 1,
                'lines' => [],
            ]);
        },
        JournalPostingValidationException::class,
        'zero_line_entries',
        'zero_line_posting_rejected'
    );

    $closeDate = nextAvailablePeriodDate($pdo, $organizationId);
    $periodId = $periodRepo->createOpenPeriod($closeDate, $closeDate);
    echo 'period_create_for_close: PASS period_id=' . $periodId . PHP_EOL;
    $periodRepo->closePeriod($periodId, 1);
    echo 'period_close: PASS period_id=' . $periodId . PHP_EOL;

    expectStructuredException(
        static function () use ($posting, $closeDate, $cashAccount, $salesAccount): void {
            $posting->post([
                'entry_date' => $closeDate,
                'reference_type' => 'smoke.test',
                'reference_id' => 'closed-period-1',
                'description' => 'Should fail due to closed period',
                'created_by' => 1,
                'branch_id' => 1,
                'lines' => [
                    ['account_id' => (int) $cashAccount['id'], 'debit_amount' => '10.0000', 'credit_amount' => '0.0000'],
                    ['account_id' => (int) $salesAccount['id'], 'debit_amount' => '0.0000', 'credit_amount' => '10.0000'],
                ],
            ]);
        },
        AccountingPeriodViolationException::class,
        'period_closed',
        'closed_period_guard'
    );

    $overlapBaseStart = nextAvailablePeriodDate($pdo, $organizationId);
    $overlapStartDt = new DateTimeImmutable($overlapBaseStart);
    $overlapBaseEnd = $overlapStartDt->modify('+27 day')->format('Y-m-d');
    $periodRepo->createOpenPeriod($overlapBaseStart, $overlapBaseEnd);
    expectStructuredException(
        static function () use ($periodRepo, $overlapStartDt): void {
            $overlapStart = $overlapStartDt->modify('+14 day')->format('Y-m-d');
            $overlapEnd = $overlapStartDt->modify('+40 day')->format('Y-m-d');
            $periodRepo->createOpenPeriod(
                $overlapStart,
                $overlapEnd
            );
        },
        AccountingPeriodViolationException::class,
        'period_overlap_detected',
        'overlapping_period_blocked'
    );

    expectStructuredException(
        static function () use ($accountRepo, $cashAccount): void {
            $accountRepo->updateAccount(
                (int) $cashAccount['id'],
                '1000X',
                'Cash - hacked',
                'asset',
                true
            );
        },
        InvariantViolationException::class,
        'system_account_code_immutable',
        'system_account_modify_blocked'
    );

    // Race simulation: pre-check open period, then close it, then lock/revalidate as posting would.
    $raceDate = nextAvailablePeriodDate($pdo, $organizationId);
    $racePeriodId = $periodRepo->createOpenPeriod($raceDate, $raceDate);
    $precheckedOpen = $guard->assertMutationAllowed($raceDate, 'journal.posting');
    $periodRepo->closePeriod($racePeriodId, 1);
    expectStructuredException(
        static function () use ($tx, $guard, $precheckedOpen, $raceDate): void {
            $tx->run(static function () use ($guard, $precheckedOpen, $raceDate): void {
                $guard->lockOpenPeriodForPosting((int) $precheckedOpen['id'], $raceDate);
            });
        },
        AccountingPeriodViolationException::class,
        'period_closed_race',
        'period_close_race_guard'
    );

    $balanceSheet = $reports->balanceSheet($date);
    if (($balanceSheet['balance_gap'] ?? '0.0000') !== '0.0000') {
        throw new RuntimeException('balance_sheet_equation_check: FAIL gap=' . ($balanceSheet['balance_gap'] ?? 'unknown'));
    }
    echo 'balance_sheet_equation_check: PASS' . PHP_EOL;

    $globalJournalIntegrity = $reports->validateJournalImbalanceAcrossOrg($organizationId, true);
    if (!($globalJournalIntegrity['ok'] ?? false)) {
        throw new RuntimeException('journal_global_integrity_check: expected balanced global journal');
    }
    echo 'journal_global_integrity_check: PASS' . PHP_EOL;

    try {
        $tx->run(function () use ($pdo, $reports, $organizationId, $date, $cashAccount): void {
            $correlationId = 'SMOKE-IMBALANCE-' . bin2hex(random_bytes(4));
            $entryStmt = $pdo->prepare('INSERT INTO journal_entries
                (organization_id, branch_id, entry_date, reference_type, reference_id, description, period_id, correlation_id, created_by)
                VALUES
                (:organization_id, :branch_id, :entry_date, :reference_type, :reference_id, :description, :period_id, :correlation_id, :created_by)');
            $entryStmt->execute([
                ':organization_id' => $organizationId,
                ':branch_id' => 1,
                ':entry_date' => $date,
                ':reference_type' => 'smoke.manual.imbalance',
                ':reference_id' => $correlationId,
                ':description' => 'Manual imbalance simulation',
                ':period_id' => null,
                ':correlation_id' => $correlationId,
                ':created_by' => 1,
            ]);
            $entryId = (int) $pdo->lastInsertId();

            $lineStmt = $pdo->prepare('INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount)
                VALUES
                (:journal_entry_id, :account_id, :debit_amount, :credit_amount)');
            $lineStmt->execute([
                ':journal_entry_id' => $entryId,
                ':account_id' => (int) $cashAccount['id'],
                ':debit_amount' => '1.0000',
                ':credit_amount' => '0.0000',
            ]);

            expectStructuredException(
                static function () use ($reports, $organizationId): void {
                    $reports->validateJournalImbalanceAcrossOrg($organizationId, true);
                },
                InvariantViolationException::class,
                'journal_global_imbalance',
                'journal_global_imbalance_detected_strict'
            );

            $warning = $reports->validateJournalImbalanceAcrossOrg($organizationId, false);
            if (($warning['ok'] ?? true) !== false) {
                throw new RuntimeException('journal_global_imbalance_non_strict: expected warning payload');
            }
            if (($warning['code'] ?? null) !== 'journal_global_imbalance') {
                throw new RuntimeException('journal_global_imbalance_non_strict: expected code journal_global_imbalance');
            }
            echo 'journal_global_imbalance_non_strict: PASS code=journal_global_imbalance' . PHP_EOL;

            throw new RuntimeException('__ROLLBACK_IMBALANCE_TEST__');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '__ROLLBACK_IMBALANCE_TEST__') {
            throw $e;
        }
        echo 'journal_global_imbalance_txn_rollback: PASS' . PHP_EOL;
    }

    $globalJournalIntegrityAfterRollback = $reports->validateJournalImbalanceAcrossOrg($organizationId, true);
    if (!($globalJournalIntegrityAfterRollback['ok'] ?? false)) {
        throw new RuntimeException('journal_global_integrity_post_rollback: expected balanced global journal');
    }
    echo 'journal_global_integrity_post_rollback: PASS' . PHP_EOL;

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
