<?php

declare(strict_types=1);

use App\Core\Database\Connection;
use App\Core\Database\TransactionManager;
use App\Core\Support\TenantScope;
use App\Domain\Accounting\AccountingPeriodGuard;
use App\Domain\Accounting\FinancialReportService;
use App\Domain\Accounting\InvariantViolationException;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Accounting\JournalPostingValidationException;
use App\Domain\Inventory\InventoryCostingService;
use App\Domain\Inventory\InventoryPostingService;
use App\Domain\Inventory\InventoryValidationException;
use App\Domain\Audit\AuditLogService;
use App\Infrastructure\Repositories\Accounting\AccountingPeriodRepository;
use App\Infrastructure\Repositories\Accounting\ChartOfAccountRepository;
use App\Infrastructure\Repositories\Accounting\JournalEntryRepository;
use App\Infrastructure\Repositories\Audit\AuditLogRepository;
use App\Infrastructure\Repositories\Inventory\InventoryLocationRepository;
use App\Infrastructure\Repositories\Inventory\ProductRepository;
use App\Infrastructure\Repositories\Inventory\StockLedgerRepository;
use App\Infrastructure\Repositories\Tenant\BranchRepository;

require __DIR__ . '/../../bootstrap.php';

/**
 * @param class-string<Throwable> $class
 */
function expectExceptionCode(callable $fn, string $class, string $code, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $class)) {
            throw new RuntimeException($label . ': unexpected exception ' . get_class($e));
        }
        if (!method_exists($e, 'details')) {
            throw new RuntimeException($label . ': missing structured details()');
        }
        $details = $e->details();
        if (($details['code'] ?? null) !== $code) {
            throw new RuntimeException($label . ': expected code ' . $code . ', got ' . ($details['code'] ?? 'null'));
        }
        echo $label . ': PASS code=' . $code . PHP_EOL;
        return;
    }

    throw new RuntimeException($label . ': expected exception was not thrown');
}

function expectDbTriggerFailure(callable $fn, string $messageContains, string $code, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        $message = $e->getMessage();
        if (stripos($message, $messageContains) === false) {
            throw new RuntimeException($label . ': expected DB trigger message containing "' . $messageContains . '", got "' . $message . '"');
        }

        echo $label . ': PASS code=' . $code . PHP_EOL;
        return;
    }

    throw new RuntimeException($label . ': expected DB trigger failure was not thrown');
}

function decimalEq(string $actual, string $expected, string $label): void
{
    if (number_format((float) $actual, 4, '.', '') !== number_format((float) $expected, 4, '.', '')) {
        throw new RuntimeException($label . ': expected ' . $expected . ', got ' . $actual);
    }
    echo $label . ': PASS value=' . number_format((float) $actual, 4, '.', '') . PHP_EOL;
}

function toScaled(string $amount): int
{
    return (int) round(((float) $amount) * 10000);
}

function toDecimal(int $amount): string
{
    return number_format($amount / 10000, 4, '.', '');
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

function firstOpenPeriodDateAfter(PDO $pdo, int $organizationId, string $afterDate): ?string
{
    $stmt = $pdo->prepare('SELECT period_start
                           FROM accounting_periods
                           WHERE organization_id = :organization_id
                             AND status = :status
                             AND period_start > :after_date
                           ORDER BY period_start ASC
                           LIMIT 1');
    $stmt->execute([
        ':organization_id' => $organizationId,
        ':status' => 'open',
        ':after_date' => $afterDate,
    ]);

    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

$dbConfig = require __DIR__ . '/../../config/database.php';

try {
    $pdo = (new Connection($dbConfig))->pdo();
    $organizationId = 1;
    $scope = new TenantScope($organizationId, null);
    $tx = new TransactionManager($pdo);

    $auditRepo = new AuditLogRepository($pdo, $scope);
    $auditService = new AuditLogService($auditRepo);
    $chartOfAccountRepo = new ChartOfAccountRepository($pdo, $scope);
    $journalRepo = new JournalEntryRepository($pdo, $scope);
    $periodRepo = new AccountingPeriodRepository($pdo, $scope);
    $branchRepo = new BranchRepository($pdo, $scope);
    $productRepo = new ProductRepository($pdo, $scope);
    $locationRepo = new InventoryLocationRepository($pdo, $scope);
    $stockLedgerRepo = new StockLedgerRepository($pdo, $scope);

    $reports = new FinancialReportService($journalRepo, $auditService, true, $stockLedgerRepo);
    $reportsSoft = new FinancialReportService($journalRepo, $auditService, false, $stockLedgerRepo);
    $periodGuard = new AccountingPeriodGuard($periodRepo, $reports);
    $journalPosting = new JournalPostingService(
        $tx,
        $journalRepo,
        $chartOfAccountRepo,
        $periodGuard,
        $branchRepo,
        $stockLedgerRepo,
        $auditService
    );
    $costing = new InventoryCostingService($stockLedgerRepo);
    $inventoryPosting = new InventoryPostingService(
        $tx,
        $costing,
        $stockLedgerRepo,
        $productRepo,
        $locationRepo,
        $branchRepo,
        $periodGuard,
        $periodRepo,
        $journalPosting,
        $chartOfAccountRepo,
        $auditService
    );

    $suffix = bin2hex(random_bytes(3));

    $latestClosed = $periodRepo->findLatestClosedPeriod($organizationId);
    if ($latestClosed === null) {
        $newClosedDate = nextAvailablePeriodDate($pdo, $organizationId);
        $newClosedPeriodId = $periodRepo->createOpenPeriod($newClosedDate, $newClosedDate, $organizationId);
        $periodRepo->closePeriod($newClosedPeriodId, 1, $organizationId);
        $latestClosed = $periodRepo->findLatestClosedPeriod($organizationId);
    }

    if ($latestClosed === null) {
        throw new RuntimeException('Unable to prepare a latest closed period for backdated guard test.');
    }

    $latestClosedEnd = (string) $latestClosed['period_end'];
    echo 'latest_closed_period_ready: PASS period_end=' . $latestClosedEnd . PHP_EOL;

    $operationDate = firstOpenPeriodDateAfter($pdo, $organizationId, $latestClosedEnd);
    if ($operationDate === null) {
        $operationDate = nextAvailablePeriodDate($pdo, $organizationId);
        $periodRepo->createOpenPeriod($operationDate, $operationDate, $organizationId);
    }
    echo 'operation_period_ready: PASS entry_date=' . $operationDate . PHP_EOL;

    $productId = $productRepo->create('SKU-' . $suffix, 'Inventory Product ' . $suffix, 'inventory', 'weighted_avg', true, $organizationId);
    echo 'product_create: PASS product_id=' . $productId . PHP_EOL;

    $sourceBranchId = 1;
    $sourceLocationId = $locationRepo->create($sourceBranchId, 'Main Store ' . $suffix, 'store', $organizationId);

    $destinationBranchCode = 'BR2' . strtoupper($suffix);
    $stmt = $pdo->prepare('SELECT id FROM branches WHERE organization_id = :org AND code = :code LIMIT 1');
    $stmt->execute([':org' => $organizationId, ':code' => $destinationBranchCode]);
    $branchRow = $stmt->fetch();
    if ($branchRow) {
        $destinationBranchId = (int) $branchRow['id'];
    } else {
        $insertBranch = $pdo->prepare('INSERT INTO branches (organization_id, code, name, status) VALUES (:org, :code, :name, :status)');
        $insertBranch->execute([
            ':org' => $organizationId,
            ':code' => $destinationBranchCode,
            ':name' => 'Branch ' . strtoupper($suffix),
            ':status' => 'active',
        ]);
        $destinationBranchId = (int) $pdo->lastInsertId();
    }

    $destinationTransitLocationId = $locationRepo->create($destinationBranchId, 'Transit ' . $suffix, 'transit', $organizationId);
    $destinationStoreLocationId = $locationRepo->create($destinationBranchId, 'Store ' . $suffix, 'store', $organizationId);

    expectExceptionCode(
        static function () use ($inventoryPosting, $organizationId, $sourceBranchId, $sourceLocationId, $productId, $latestClosedEnd, $suffix): void {
            $inventoryPosting->purchaseReceive([
                'organization_id' => $organizationId,
                'branch_id' => $sourceBranchId,
                'location_id' => $sourceLocationId,
                'product_id' => $productId,
                'quantity' => '1.0000',
                'unit_cost' => '10.0000',
                'entry_date' => $latestClosedEnd,
                'created_by' => 1,
                'reference_type' => 'smoke.backdated.purchase',
                'reference_id' => 'PO-BACKDATED-' . $suffix,
            ]);
        },
        InventoryValidationException::class,
        'entry_date_before_latest_closed_period',
        'backdated_movement_blocked'
    );

    $purchase = $inventoryPosting->purchaseReceive([
        'organization_id' => $organizationId,
        'branch_id' => $sourceBranchId,
        'location_id' => $sourceLocationId,
        'product_id' => $productId,
        'quantity' => '100.0000',
        'unit_cost' => '10.0000',
        'entry_date' => $operationDate,
        'created_by' => 1,
        'reference_type' => 'smoke.purchase',
        'reference_id' => 'PO-' . $suffix,
    ]);
    echo 'purchase_receive: PASS stock_ledger_id=' . $purchase['stock_ledger_id'] . PHP_EOL;

    expectDbTriggerFailure(
        static function () use ($pdo, $purchase): void {
            $stmt = $pdo->prepare('UPDATE stock_ledger SET quantity = :quantity WHERE id = :id');
            $stmt->execute([
                ':quantity' => '999.0000',
                ':id' => (int) $purchase['stock_ledger_id'],
            ]);
        },
        'Stock ledger entries are immutable',
        'stock_ledger_immutable',
        'stock_ledger_update_blocked'
    );

    $sale = $inventoryPosting->saleIssue([
        'organization_id' => $organizationId,
        'branch_id' => $sourceBranchId,
        'location_id' => $sourceLocationId,
        'product_id' => $productId,
        'quantity' => '40.0000',
        'entry_date' => $operationDate,
        'created_by' => 1,
        'reference_type' => 'smoke.sale',
        'reference_id' => 'SO-' . $suffix,
    ]);
    echo 'sale_issue: PASS stock_ledger_id=' . $sale['stock_ledger_id'] . PHP_EOL;

    $snapshot = $costing->snapshot($productId, $sourceBranchId, $operationDate, $organizationId, false);
    decimalEq($snapshot['quantity'], '60.0000', 'stock_after_sale');
    decimalEq($snapshot['value'], '600.0000', 'inventory_value_after_sale');
    decimalEq($sale['cogs_value'], '400.0000', 'cogs_after_sale');

    $cogsSummary = $reports->cogsSummary($operationDate, $operationDate, $sourceBranchId, $organizationId);
    decimalEq($cogsSummary['stock_ledger_cogs'], '400.0000', 'cogs_summary_stock');
    decimalEq($cogsSummary['journal_cogs'], '400.0000', 'cogs_summary_journal');

    $transitBalanceBefore = (string) $journalRepo->sumAccountNetByCodeAsOf('1305', $operationDate, null, $organizationId);

    $transfer = $inventoryPosting->transfer([
        'organization_id' => $organizationId,
        'source_branch_id' => $sourceBranchId,
        'source_location_id' => $sourceLocationId,
        'destination_branch_id' => $destinationBranchId,
        'destination_location_id' => $destinationTransitLocationId,
        'product_id' => $productId,
        'quantity' => '10.0000',
        'entry_date' => $operationDate,
        'created_by' => 1,
        'reference_type' => 'smoke.transfer',
        'reference_id' => 'TR-' . $suffix,
    ]);
    echo 'transfer_issue: PASS journal_entry_id=' . $transfer['journal_entry_id'] . PHP_EOL;

    $transferOutLeg = $stockLedgerRepo->findById((int) $transfer['transfer_out_stock_ledger_id'], $organizationId);
    if ($transferOutLeg === null) {
        throw new RuntimeException('transfer_issue_value_check: transfer out stock ledger row not found');
    }
    $transferValueScaled = (int) round((toScaled((string) $transferOutLeg['quantity']) * toScaled((string) $transferOutLeg['unit_cost'])) / 10000);
    $transitAfterIssue = (string) $journalRepo->sumAccountNetByCodeAsOf('1305', $operationDate, null, $organizationId);
    decimalEq(
        $transitAfterIssue,
        toDecimal(toScaled($transitBalanceBefore) + $transferValueScaled),
        'stock_in_transit_after_issue'
    );

    $transferReceive = $inventoryPosting->transferReceive([
        'organization_id' => $organizationId,
        'destination_branch_id' => $destinationBranchId,
        'destination_location_id' => $destinationStoreLocationId,
        'transfer_out_stock_ledger_id' => $transfer['transfer_out_stock_ledger_id'],
        'transfer_in_stock_ledger_id' => $transfer['transfer_in_stock_ledger_id'],
        'entry_date' => $operationDate,
        'created_by' => 1,
        'reference_type' => 'inventory.transfer.receive',
        'reference_id' => (string) $transfer['transfer_in_stock_ledger_id'],
        'correlation_id' => $transfer['correlation_id'],
    ]);
    echo 'transfer_receive: PASS journal_entry_id=' . $transferReceive['journal_entry_id'] . PHP_EOL;

    $sourceBalance = $stockLedgerRepo->aggregateProductLocationBalance($productId, $sourceLocationId, $operationDate, $organizationId);
    decimalEq($sourceBalance['quantity'], '50.0000', 'source_stock_after_transfer_lifecycle');
    $transitBalance = $stockLedgerRepo->aggregateProductLocationBalance($productId, $destinationTransitLocationId, $operationDate, $organizationId);
    decimalEq($transitBalance['quantity'], '0.0000', 'transit_stock_after_transfer_lifecycle');
    $destinationStoreBalance = $stockLedgerRepo->aggregateProductLocationBalance($productId, $destinationStoreLocationId, $operationDate, $organizationId);
    decimalEq($destinationStoreBalance['quantity'], '10.0000', 'destination_store_stock_after_transfer_lifecycle');

    $transitAfterReceive = (string) $journalRepo->sumAccountNetByCodeAsOf('1305', $operationDate, null, $organizationId);
    decimalEq($transitAfterReceive, $transitBalanceBefore, 'stock_in_transit_account_cleared_for_lifecycle');

    $inventoryAccount = $chartOfAccountRepo->findByCode('1300', $organizationId);
    $purchaseClearingAccount = $chartOfAccountRepo->findByCode('2200', $organizationId);
    if ($inventoryAccount === null || $purchaseClearingAccount === null) {
        throw new RuntimeException('Required accounts for integrity tests are missing.');
    }

    expectExceptionCode(
        static function () use ($journalPosting, $organizationId, $operationDate, $inventoryAccount, $purchaseClearingAccount, $suffix): void {
            $journalPosting->post([
                'organization_id' => $organizationId,
                'branch_id' => 1,
                'entry_date' => $operationDate,
                'reference_type' => 'smoke.manual.inventory',
                'reference_id' => 'MI-' . $suffix,
                'description' => 'Manual inventory account posting should be blocked',
                'created_by' => 1,
                'lines' => [
                    [
                        'account_id' => (int) $inventoryAccount['id'],
                        'debit_amount' => '5.0000',
                        'credit_amount' => '0.0000',
                    ],
                    [
                        'account_id' => (int) $purchaseClearingAccount['id'],
                        'debit_amount' => '0.0000',
                        'credit_amount' => '5.0000',
                    ],
                ],
            ]);
        },
        JournalPostingValidationException::class,
        'manual_inventory_account_blocked',
        'manual_inventory_journal_blocked'
    );

    try {
        $tx->run(function () use ($pdo, $reports, $reportsSoft, $organizationId, $sourceBranchId, $sourceLocationId, $productId, $operationDate, $suffix): void {
            $manualCorrelation = 'SMOKE-LINK-' . strtoupper($suffix);
            $stmt = $pdo->prepare('INSERT INTO stock_ledger
                (organization_id, branch_id, product_id, location_id, movement_type, quantity, unit_cost, reference_type, reference_id, correlation_id, entry_date)
                VALUES
                (:organization_id, :branch_id, :product_id, :location_id, :movement_type, :quantity, :unit_cost, :reference_type, :reference_id, :correlation_id, :entry_date)');
            $stmt->execute([
                ':organization_id' => $organizationId,
                ':branch_id' => $sourceBranchId,
                ':product_id' => $productId,
                ':location_id' => $sourceLocationId,
                ':movement_type' => 'purchase',
                ':quantity' => '1.0000',
                ':unit_cost' => '10.0000',
                ':reference_type' => 'smoke.manual.stock_only',
                ':reference_id' => 'MSO-' . $suffix,
                ':correlation_id' => $manualCorrelation,
                ':entry_date' => $operationDate,
            ]);

            expectExceptionCode(
                static function () use ($reports, $organizationId): void {
                    $reports->validateInventoryJournalLinkIntegrity(true, null, $organizationId);
                },
                InvariantViolationException::class,
                'inventory_journal_link_mismatch',
                'stock_without_journal_detected'
            );

            $softLink = $reportsSoft->validateInventoryJournalLinkIntegrity(false, null, $organizationId);
            if (($softLink['ok'] ?? true) !== false) {
                throw new RuntimeException('stock_without_journal_non_strict: expected warning mode result.');
            }
            echo 'stock_without_journal_non_strict: PASS code=' . ($softLink['code'] ?? 'unknown') . PHP_EOL;

            throw new RuntimeException('__ROLLBACK_LINK_TEST__');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '__ROLLBACK_LINK_TEST__') {
            throw $e;
        }
        echo 'stock_without_journal_txn_rollback: PASS' . PHP_EOL;
    }

    $negativeProductId = $productRepo->create('NEG-' . $suffix, 'Negative Drift Product ' . $suffix, 'inventory', 'weighted_avg', true, $organizationId);
    try {
        $tx->run(function () use ($pdo, $reports, $reportsSoft, $organizationId, $sourceBranchId, $sourceLocationId, $negativeProductId, $operationDate, $suffix): void {
            $stmt = $pdo->prepare('INSERT INTO stock_ledger
                (organization_id, branch_id, product_id, location_id, movement_type, quantity, unit_cost, reference_type, reference_id, correlation_id, entry_date)
                VALUES
                (:organization_id, :branch_id, :product_id, :location_id, :movement_type, :quantity, :unit_cost, :reference_type, :reference_id, :correlation_id, :entry_date)');
            $stmt->execute([
                ':organization_id' => $organizationId,
                ':branch_id' => $sourceBranchId,
                ':product_id' => $negativeProductId,
                ':location_id' => $sourceLocationId,
                ':movement_type' => 'sale',
                ':quantity' => '3.0000',
                ':unit_cost' => '2.0000',
                ':reference_type' => 'smoke.manual.negative',
                ':reference_id' => 'NEG-' . $suffix,
                ':correlation_id' => 'NEG-CORR-' . strtoupper($suffix),
                ':entry_date' => $operationDate,
            ]);

            expectExceptionCode(
                static function () use ($reports, $organizationId): void {
                    $reports->validateNoNegativeInventory(true, $organizationId);
                },
                InvariantViolationException::class,
                'negative_inventory_detected',
                'negative_inventory_detected'
            );

            $softNegative = $reportsSoft->validateNoNegativeInventory(false, $organizationId);
            if (($softNegative['ok'] ?? true) !== false) {
                throw new RuntimeException('negative_inventory_non_strict: expected warning mode result.');
            }
            echo 'negative_inventory_non_strict: PASS code=' . ($softNegative['code'] ?? 'unknown') . PHP_EOL;

            throw new RuntimeException('__ROLLBACK_NEGATIVE_TEST__');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '__ROLLBACK_NEGATIVE_TEST__') {
            throw $e;
        }
        echo 'negative_inventory_txn_rollback: PASS' . PHP_EOL;
    }

    $linkIntegrity = $reports->validateInventoryJournalLinkIntegrity(true, null, $organizationId);
    if (!($linkIntegrity['ok'] ?? false)) {
        throw new RuntimeException('inventory_journal_link_integrity_final_check: expected no mismatches.');
    }
    echo 'inventory_journal_link_integrity_final_check: PASS' . PHP_EOL;

    $negativeIntegrity = $reports->validateNoNegativeInventory(true, $organizationId);
    if (!($negativeIntegrity['ok'] ?? false)) {
        throw new RuntimeException('negative_inventory_integrity_final_check: expected no negative balances.');
    }
    echo 'negative_inventory_integrity_final_check: PASS' . PHP_EOL;

    $integrity = $reports->validateInventoryAccountIntegrity($operationDate, null, $organizationId);
    if (!($integrity['is_matching'] ?? false)) {
        throw new RuntimeException('inventory_integrity_final_check: expected aligned valuation vs inventory account');
    }
    decimalEq((string) $integrity['difference'], '0.0000', 'inventory_integrity_final_difference');

    $trialBalance = $reports->trialBalance($operationDate, $operationDate, null, $organizationId);
    if (!($trialBalance['is_balanced'] ?? false)) {
        throw new RuntimeException('journal_balance_check: expected balanced trial balance');
    }
    echo 'journal_balance_check: PASS' . PHP_EOL;

    expectExceptionCode(
        static function () use ($inventoryPosting, $organizationId, $sourceBranchId, $sourceLocationId, $productId, $operationDate, $suffix): void {
            $inventoryPosting->saleIssue([
                'organization_id' => $organizationId,
                'branch_id' => $sourceBranchId,
                'location_id' => $sourceLocationId,
                'product_id' => $productId,
                'quantity' => '100.0000',
                'entry_date' => $operationDate,
                'created_by' => 1,
                'reference_type' => 'smoke.sale',
                'reference_id' => 'SO-OVER-' . $suffix,
            ]);
        },
        InventoryValidationException::class,
        'insufficient_stock',
        'oversell_blocked'
    );

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Inventory accounting smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
