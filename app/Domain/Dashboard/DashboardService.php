<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Accounting\FinancialReportService;
use App\Domain\Config\ConfigService;

final class DashboardService
{
    public function __construct(
        private readonly ?FinancialReportService $reports = null,
        private readonly ?ConfigService $config = null
    )
    {
    }

    public function summary(?int $organizationId = null, ?int $branchId = null): array
    {
        $metrics = [
            'today_revenue' => '0.0000',
            'total_assets' => '0.0000',
            'total_liabilities' => '0.0000',
            'net_profit_ytd' => '0.0000',
            'trial_balance_is_balanced' => true,
            'trial_balance_difference' => '0.0000',
            'inventory_integrity_is_matching' => true,
            'inventory_integrity_difference' => '0.0000',
            'inventory_journal_link_ok' => true,
            'inventory_journal_link_missing_count' => 0,
            'inventory_journal_link_orphan_count' => 0,
            'negative_inventory_ok' => true,
            'negative_inventory_count' => 0,
            'journal_global_balance_ok' => true,
            'journal_global_balance_difference' => '0.0000',
        ];

        if ($this->reports !== null) {
            try {
                $metrics = $this->reports->dashboardMetrics($branchId, $organizationId);
            } catch (\Throwable) {
                // Keep dashboard available even if accounting migrations are not applied yet.
            }
        }

        if ($this->reports !== null && $organizationId !== null) {
            $strictMode = $this->config?->accountingStrictMode($organizationId) ?? true;
            $globalJournalIntegrity = $this->reports->validateJournalImbalanceAcrossOrg($organizationId, $strictMode);
            $metrics['journal_global_balance_ok'] = $globalJournalIntegrity['ok'];
            $metrics['journal_global_balance_difference'] = $globalJournalIntegrity['difference'];
        }

        $trialStatus = $metrics['trial_balance_is_balanced']
            ? 'Balanced'
            : 'Out of Balance (' . $metrics['trial_balance_difference'] . ')';
        $inventoryIntegrityStatus = $metrics['inventory_integrity_is_matching']
            ? 'Aligned'
            : 'Mismatch (' . $metrics['inventory_integrity_difference'] . ')';
        $inventoryJournalLinkStatus = $metrics['inventory_journal_link_ok']
            ? 'Linked'
            : 'Mismatch (missing ' . $metrics['inventory_journal_link_missing_count'] . ', orphan ' . $metrics['inventory_journal_link_orphan_count'] . ')';
        $negativeInventoryStatus = $metrics['negative_inventory_ok']
            ? 'None Detected'
            : 'Detected (' . $metrics['negative_inventory_count'] . ')';
        $journalBalanceIntegrityStatus = $metrics['journal_global_balance_ok']
            ? 'Balanced'
            : 'Imbalance (' . $metrics['journal_global_balance_difference'] . ')';
        $journalBalanceIntegrityIndicator = $metrics['journal_global_balance_ok'] ? 'green' : 'red';

        return [
            'kpis' => [
                ['title' => 'Today Revenue', 'value' => (string) $metrics['today_revenue'], 'icon' => 'fa-chart-line'],
                ['title' => 'Total Assets', 'value' => (string) $metrics['total_assets'], 'icon' => 'fa-wallet'],
                ['title' => 'Total Liabilities', 'value' => (string) $metrics['total_liabilities'], 'icon' => 'fa-scale-balanced'],
                ['title' => 'Net Profit YTD', 'value' => (string) $metrics['net_profit_ytd'], 'icon' => 'fa-percent'],
                ['title' => 'Trial Balance Status', 'value' => $trialStatus, 'icon' => 'fa-shield-halved'],
                ['title' => 'Inventory Integrity', 'value' => $inventoryIntegrityStatus, 'icon' => 'fa-boxes-stacked'],
                ['title' => 'Stock-Journal Link', 'value' => $inventoryJournalLinkStatus, 'icon' => 'fa-link'],
                ['title' => 'Negative Inventory', 'value' => $negativeInventoryStatus, 'icon' => 'fa-triangle-exclamation'],
                ['title' => 'Journal Global Balance', 'value' => $journalBalanceIntegrityStatus, 'icon' => 'fa-scale-balanced'],
            ],
            'trial_balance_status' => $trialStatus,
            'inventory_integrity_status' => $inventoryIntegrityStatus,
            'inventory_journal_link_status' => $inventoryJournalLinkStatus,
            'negative_inventory_status' => $negativeInventoryStatus,
            'journal_balance_integrity_status' => $journalBalanceIntegrityStatus,
            'journal_balance_integrity_difference' => (string) $metrics['journal_global_balance_difference'],
            'journal_balance_integrity_indicator' => $journalBalanceIntegrityIndicator,
        ];
    }
}
