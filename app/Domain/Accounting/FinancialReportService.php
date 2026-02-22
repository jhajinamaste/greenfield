<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Domain\Audit\AuditLogService;
use App\Infrastructure\Repositories\Accounting\JournalEntryRepository;
use App\Infrastructure\Repositories\Inventory\StockLedgerRepository;

final class FinancialReportService
{
    public function __construct(
        private readonly JournalEntryRepository $journals,
        private readonly ?AuditLogService $audit = null,
        private readonly bool $strictMode = true,
        private readonly ?StockLedgerRepository $stockLedger = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function trialBalance(
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $rows = $this->journals->fetchTrialBalanceRows($fromDate, $toDate, $branchId, $organizationId);

        $totalDebitScaled = 0;
        $totalCreditScaled = 0;
        $normalizedRows = [];
        foreach ($rows as $row) {
            $debitScaled = $this->toScaled((string) $row['total_debit']);
            $creditScaled = $this->toScaled((string) $row['total_credit']);
            $totalDebitScaled += $debitScaled;
            $totalCreditScaled += $creditScaled;

            $normalizedRows[] = [
                'account_id' => (int) $row['account_id'],
                'account_code' => $row['account_code'],
                'account_name' => $row['account_name'],
                'account_type' => $row['account_type'],
                'total_debit' => $this->toDecimal($debitScaled),
                'total_credit' => $this->toDecimal($creditScaled),
                'net_amount' => $this->toDecimal($this->toScaled((string) $row['net_amount'])),
            ];
        }

        $differenceScaled = $totalDebitScaled - $totalCreditScaled;

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'total_debit' => $this->toDecimal($totalDebitScaled),
            'total_credit' => $this->toDecimal($totalCreditScaled),
            'difference' => $this->toDecimal($differenceScaled),
            'is_balanced' => $differenceScaled === 0,
            'rows' => $normalizedRows,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function profitAndLoss(
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $typeRows = $this->journals->fetchTypeBalancesBetween($fromDate, $toDate, $branchId, $organizationId);
        $incomeScaled = 0;
        $expenseScaled = 0;

        foreach ($typeRows as $row) {
            $debitScaled = $this->toScaled((string) $row['total_debit']);
            $creditScaled = $this->toScaled((string) $row['total_credit']);

            if ($row['account_type'] === 'income') {
                $incomeScaled += ($creditScaled - $debitScaled);
            } elseif ($row['account_type'] === 'expense') {
                $expenseScaled += ($debitScaled - $creditScaled);
            }
        }

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'income_total' => $this->toDecimal($incomeScaled),
            'expense_total' => $this->toDecimal($expenseScaled),
            'net_profit' => $this->toDecimal($incomeScaled - $expenseScaled),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function balanceSheet(
        string $asOfDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $typeRows = $this->journals->fetchTypeBalancesAsOf($asOfDate, $branchId, $organizationId);
        $balances = [
            'asset' => 0,
            'liability' => 0,
            'equity' => 0,
            'income' => 0,
            'expense' => 0,
        ];

        foreach ($typeRows as $row) {
            $debitScaled = $this->toScaled((string) $row['total_debit']);
            $creditScaled = $this->toScaled((string) $row['total_credit']);
            $type = (string) $row['account_type'];

            if ($type === 'asset' || $type === 'expense') {
                $balances[$type] += $debitScaled - $creditScaled;
                continue;
            }

            $balances[$type] += $creditScaled - $debitScaled;
        }

        $retainedEarningsScaled = $balances['income'] - $balances['expense'];
        $totalAssetsScaled = $balances['asset'];
        $totalLiabilitiesScaled = $balances['liability'];
        $totalEquityScaled = $balances['equity'] + $retainedEarningsScaled;
        $liabilitiesAndEquityScaled = $totalLiabilitiesScaled + $totalEquityScaled;
        $balanceGapScaled = $totalAssetsScaled - $liabilitiesAndEquityScaled;

        if ($balanceGapScaled !== 0) {
            if ($this->audit !== null) {
                $this->audit->log([
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'user_id' => null,
                    'action_type' => 'financial.balance_sheet_invariant_violation',
                    'entity_type' => 'financial_report',
                    'entity_id' => 'balance_sheet:' . $asOfDate,
                    'metadata' => [
                        'as_of_date' => $asOfDate,
                        'strict_mode' => $this->strictMode,
                    ],
                    'before_state' => null,
                    'after_state' => [
                        'total_assets' => $this->toDecimal($totalAssetsScaled),
                        'liabilities_and_equity' => $this->toDecimal($liabilitiesAndEquityScaled),
                        'balance_gap' => $this->toDecimal($balanceGapScaled),
                    ],
                    'request_id' => null,
                    'correlation_id' => null,
                    'ip' => null,
                    'ip_address' => null,
                    'user_agent' => 'financial_report_invariant_check',
                ]);
            }

            if ($this->strictMode) {
                throw new InvariantViolationException([
                    'code' => 'balance_sheet_mismatch',
                    'as_of_date' => $asOfDate,
                    'total_assets' => $this->toDecimal($totalAssetsScaled),
                    'liabilities_and_equity' => $this->toDecimal($liabilitiesAndEquityScaled),
                    'balance_gap' => $this->toDecimal($balanceGapScaled),
                ], 'Balance sheet invariant failed: assets must equal liabilities plus equity.');
            }
        }

        return [
            'as_of_date' => $asOfDate,
            'total_assets' => $this->toDecimal($totalAssetsScaled),
            'total_liabilities' => $this->toDecimal($totalLiabilitiesScaled),
            'total_equity' => $this->toDecimal($totalEquityScaled),
            'retained_earnings' => $this->toDecimal($retainedEarningsScaled),
            'liabilities_and_equity' => $this->toDecimal($liabilitiesAndEquityScaled),
            'balance_gap' => $this->toDecimal($balanceGapScaled),
        ];
    }

    /**
     * @return array<string, string|bool>
     */
    public function dashboardMetrics(
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $ytdStart = (new \DateTimeImmutable('first day of january ' . date('Y')))->format('Y-m-d');

        $plToday = $this->profitAndLoss($today, $today, $branchId, $organizationId);
        $balanceSheet = $this->balanceSheet($today, $branchId, $organizationId);
        $plYtd = $this->profitAndLoss($ytdStart, $today, $branchId, $organizationId);
        $trialBalance = $this->trialBalance($ytdStart, $today, $branchId, $organizationId);
        $inventoryIntegrity = [
            'is_matching' => true,
            'difference' => '0.0000',
        ];
        $inventoryJournalLinkIntegrity = [
            'ok' => true,
            'missingJournalCount' => 0,
            'orphanJournalCount' => 0,
        ];
        $negativeInventoryIntegrity = [
            'ok' => true,
            'negativeCount' => 0,
        ];

        if ($this->stockLedger !== null) {
            $inventoryIntegrity = $this->validateInventoryAccountIntegrity($today, $branchId, $organizationId, false);
            $inventoryJournalLinkIntegrity = $this->validateInventoryJournalLinkIntegrity(false, $branchId, $organizationId);
            $negativeInventoryIntegrity = $this->validateNoNegativeInventory(false, $organizationId);
        }

        return [
            'today_revenue' => $plToday['income_total'],
            'total_assets' => $balanceSheet['total_assets'],
            'total_liabilities' => $balanceSheet['total_liabilities'],
            'net_profit_ytd' => $plYtd['net_profit'],
            'trial_balance_is_balanced' => $trialBalance['is_balanced'],
            'trial_balance_difference' => $trialBalance['difference'],
            'inventory_integrity_is_matching' => $inventoryIntegrity['is_matching'],
            'inventory_integrity_difference' => $inventoryIntegrity['difference'],
            'inventory_journal_link_ok' => $inventoryJournalLinkIntegrity['ok'],
            'inventory_journal_link_missing_count' => $inventoryJournalLinkIntegrity['missingJournalCount'],
            'inventory_journal_link_orphan_count' => $inventoryJournalLinkIntegrity['orphanJournalCount'],
            'negative_inventory_ok' => $negativeInventoryIntegrity['ok'],
            'negative_inventory_count' => $negativeInventoryIntegrity['negativeCount'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryValuationReport(
        string $asOfDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        if ($this->stockLedger === null) {
            throw new InvariantViolationException([
                'code' => 'inventory_reporting_not_configured',
            ], 'Stock ledger repository is required for inventory valuation reporting.');
        }

        $rows = $this->stockLedger->inventoryValuationRows($asOfDate, $branchId, false, $organizationId);
        $totalQuantityScaled = 0;
        $totalValueScaled = 0;
        $normalizedRows = [];
        foreach ($rows as $row) {
            $qtyScaled = $this->toScaled((string) $row['quantity']);
            $valueScaled = $this->toScaled((string) $row['value']);
            $unitCostScaled = $qtyScaled > 0 ? (int) round(($valueScaled * 10000) / $qtyScaled) : 0;

            $totalQuantityScaled += $qtyScaled;
            $totalValueScaled += $valueScaled;
            $normalizedRows[] = [
                'product_id' => (int) $row['product_id'],
                'sku' => $row['sku'],
                'product_name' => $row['product_name'],
                'branch_id' => (int) $row['branch_id'],
                'quantity' => $this->toDecimal($qtyScaled),
                'value' => $this->toDecimal($valueScaled),
                'unit_cost' => $this->toDecimal($unitCostScaled),
            ];
        }

        $journalInventoryNet = $this->toScaled(
            $this->journals->sumAccountNetByCodeAsOf('1300', $asOfDate, $branchId, $organizationId)
        );

        return [
            'as_of_date' => $asOfDate,
            'total_quantity' => $this->toDecimal($totalQuantityScaled),
            'stock_valuation_total' => $this->toDecimal($totalValueScaled),
            'journal_inventory_balance' => $this->toDecimal($journalInventoryNet),
            'valuation_vs_journal_gap' => $this->toDecimal($totalValueScaled - $journalInventoryNet),
            'rows' => $normalizedRows,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function cogsSummary(
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        if ($this->stockLedger === null) {
            throw new InvariantViolationException([
                'code' => 'inventory_reporting_not_configured',
            ], 'Stock ledger repository is required for COGS summary.');
        }

        $stockCogsScaled = $this->toScaled(
            $this->stockLedger->cogsTotalFromStock($fromDate, $toDate, $branchId, $organizationId)
        );
        $journalCogsScaled = $this->toScaled(
            $this->journals->sumAccountNetByCodeBetween('5200', $fromDate, $toDate, $branchId, $organizationId)
        );

        return [
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'stock_ledger_cogs' => $this->toDecimal($stockCogsScaled),
            'journal_cogs' => $this->toDecimal($journalCogsScaled),
            'difference' => $this->toDecimal($stockCogsScaled - $journalCogsScaled),
        ];
    }

    /**
     * @return array<string, string|bool|int|null>
     */
    public function validateInventoryAccountIntegrity(
        string $asOfDate,
        ?int $branchId = null,
        ?int $organizationId = null,
        ?bool $strictModeOverride = null
    ): array {
        if ($this->stockLedger === null) {
            throw new InvariantViolationException([
                'code' => 'inventory_reporting_not_configured',
            ], 'Stock ledger repository is required for inventory-account integrity checks.');
        }

        $rows = $this->stockLedger->inventoryValuationRows($asOfDate, $branchId, false, $organizationId);
        $stockValuationScaled = 0;
        foreach ($rows as $row) {
            $stockValuationScaled += $this->toScaled((string) $row['value']);
        }

        $journalInventoryScaled = $this->toScaled(
            $this->journals->sumAccountNetByCodeAsOf('1300', $asOfDate, $branchId, $organizationId)
        );

        $differenceScaled = $stockValuationScaled - $journalInventoryScaled;
        $isMatching = $differenceScaled === 0;
        $result = [
            'code' => $isMatching ? 'inventory_account_aligned' : 'inventory_account_mismatch',
            'as_of_date' => $asOfDate,
            'branch_id' => $branchId,
            'organization_id' => $organizationId,
            'stock_valuation_total' => $this->toDecimal($stockValuationScaled),
            'journal_inventory_balance' => $this->toDecimal($journalInventoryScaled),
            'difference' => $this->toDecimal($differenceScaled),
            'is_matching' => $isMatching,
            'checked_rows' => count($rows),
        ];

        if ($isMatching) {
            return $result;
        }

        if ($this->audit !== null) {
            $this->audit->log([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'user_id' => null,
                'action_type' => 'financial.inventory_account_integrity_mismatch',
                'entity_type' => 'financial_report',
                'entity_id' => 'inventory_integrity:' . $asOfDate,
                'metadata' => [
                    'severity' => 'critical',
                    'strict_mode' => $strictModeOverride ?? $this->strictMode,
                ],
                'before_state' => null,
                'after_state' => $result,
                'request_id' => null,
                'correlation_id' => null,
                'ip' => null,
                'ip_address' => null,
                'user_agent' => 'inventory_account_integrity_check',
            ]);
        }

        if ($strictModeOverride ?? $this->strictMode) {
            throw new InvariantViolationException($result, 'Inventory valuation does not match the Inventory account balance.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function validateInventoryJournalLinkIntegrity(
        ?bool $strictModeOverride = null,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        if ($this->stockLedger === null) {
            throw new InvariantViolationException([
                'code' => 'inventory_reporting_not_configured',
            ], 'Stock ledger repository is required for inventory-journal link integrity checks.');
        }

        $stockCorrelationIds = $this->stockLedger->fetchAccountingImpactCorrelationIds($branchId, $organizationId);
        $journalCorrelationIds = $this->journals->fetchJournalCorrelationIds($branchId, $organizationId);
        $inventoryJournalCorrelationIds = $this->journals->fetchJournalCorrelationIdsByAccountCode('1300', $branchId, $organizationId);

        $stockSet = array_fill_keys($stockCorrelationIds, true);
        $journalSet = array_fill_keys($journalCorrelationIds, true);
        $inventoryJournalSet = array_fill_keys($inventoryJournalCorrelationIds, true);

        $missingJournalIds = [];
        foreach ($stockSet as $correlationId => $unused) {
            if (!isset($journalSet[$correlationId])) {
                $missingJournalIds[] = $correlationId;
            }
        }

        $orphanJournalIds = [];
        foreach ($inventoryJournalSet as $correlationId => $unused) {
            if (!isset($stockSet[$correlationId])) {
                $orphanJournalIds[] = $correlationId;
            }
        }

        $mismatches = [];
        foreach ($missingJournalIds as $correlationId) {
            $mismatches[] = [
                'correlation_id' => $correlationId,
                'issue' => 'stock_without_journal',
            ];
        }
        foreach ($orphanJournalIds as $correlationId) {
            $mismatches[] = [
                'correlation_id' => $correlationId,
                'issue' => 'inventory_journal_without_stock',
            ];
        }

        $result = [
            'code' => empty($mismatches) ? 'inventory_journal_links_ok' : 'inventory_journal_link_mismatch',
            'ok' => empty($mismatches),
            'missingJournalCount' => count($missingJournalIds),
            'orphanJournalCount' => count($orphanJournalIds),
            'mismatchedCorrelationIds' => $mismatches,
            'checkedStockCorrelationCount' => count($stockCorrelationIds),
            'checkedJournalCorrelationCount' => count($journalCorrelationIds),
        ];

        if ($result['ok']) {
            return $result;
        }

        $strictMode = $strictModeOverride ?? $this->strictMode;

        if ($this->audit !== null) {
            $this->audit->log([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'user_id' => null,
                'action_type' => 'financial.inventory_journal_link_mismatch',
                'entity_type' => 'financial_report',
                'entity_id' => 'inventory_journal_link_integrity',
                'metadata' => [
                    'severity' => 'critical',
                    'strict_mode' => $strictMode,
                ],
                'before_state' => null,
                'after_state' => $result,
                'request_id' => null,
                'correlation_id' => null,
                'ip' => null,
                'ip_address' => null,
                'user_agent' => 'inventory_journal_link_integrity_check',
            ]);
        }

        if ($strictMode) {
            throw new InvariantViolationException($result, 'Inventory and journal correlation integrity check failed.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function validateNoNegativeInventory(
        ?bool $strictModeOverride = null,
        ?int $organizationId = null
    ): array {
        if ($this->stockLedger === null) {
            throw new InvariantViolationException([
                'code' => 'inventory_reporting_not_configured',
            ], 'Stock ledger repository is required for negative inventory checks.');
        }

        $negativeRows = $this->stockLedger->fetchNegativeInventoryBalances($organizationId);
        $result = [
            'code' => $negativeRows === [] ? 'negative_inventory_not_detected' : 'negative_inventory_detected',
            'ok' => $negativeRows === [],
            'negativeCount' => count($negativeRows),
            'rows' => $negativeRows,
        ];

        if ($result['ok']) {
            return $result;
        }

        $strictMode = $strictModeOverride ?? $this->strictMode;

        if ($this->audit !== null) {
            $this->audit->log([
                'organization_id' => $organizationId,
                'branch_id' => null,
                'user_id' => null,
                'action_type' => 'financial.negative_inventory_detected',
                'entity_type' => 'financial_report',
                'entity_id' => 'negative_inventory_integrity',
                'metadata' => [
                    'severity' => 'critical',
                    'strict_mode' => $strictMode,
                ],
                'before_state' => null,
                'after_state' => $result,
                'request_id' => null,
                'correlation_id' => null,
                'ip' => null,
                'ip_address' => null,
                'user_agent' => 'negative_inventory_integrity_check',
            ]);
        }

        if ($strictMode) {
            throw new InvariantViolationException($result, 'Negative inventory balances were detected.');
        }

        return $result;
    }

    /**
     * @return array{ok:bool,totalDebits:string,totalCredits:string,difference:string,code:string}
     */
    public function validateJournalImbalanceAcrossOrg(
        int $organizationId,
        bool $strictMode = true
    ): array {
        $totals = $this->journals->fetchOrganizationJournalTotals($organizationId);
        $totalDebitsScaled = $this->toScaled($totals['total_debits']);
        $totalCreditsScaled = $this->toScaled($totals['total_credits']);
        $differenceScaled = $totalDebitsScaled - $totalCreditsScaled;

        $result = [
            'code' => $differenceScaled === 0 ? 'journal_global_balanced' : 'journal_global_imbalance',
            'ok' => $differenceScaled === 0,
            'totalDebits' => $this->toDecimal($totalDebitsScaled),
            'totalCredits' => $this->toDecimal($totalCreditsScaled),
            'difference' => $this->toDecimal($differenceScaled),
        ];

        if ($differenceScaled === 0) {
            return $result;
        }

        if ($this->audit !== null) {
            $this->audit->log([
                'organization_id' => $organizationId,
                'branch_id' => null,
                'user_id' => null,
                'action_type' => 'journal_global_imbalance_detected',
                'entity_type' => 'financial_report',
                'entity_id' => 'journal_global_imbalance',
                'metadata' => [
                    'severity' => 'critical',
                    'strict_mode' => $strictMode,
                ],
                'before_state' => null,
                'after_state' => $result,
                'request_id' => null,
                'correlation_id' => null,
                'ip' => null,
                'ip_address' => null,
                'user_agent' => 'journal_global_imbalance_check',
            ]);
        }

        if ($strictMode) {
            throw new InvariantViolationException($result, 'Global journal imbalance detected.');
        }

        return $result;
    }

    private function toScaled(string $amount): int
    {
        return (int) round(((float) $amount) * 10000);
    }

    private function toDecimal(int $amount): string
    {
        return number_format($amount / 10000, 4, '.', '');
    }
}
