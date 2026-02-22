<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Accounting\FinancialReportService;

final class DashboardService
{
    public function __construct(private readonly ?FinancialReportService $reports = null)
    {
    }

    public function summary(): array
    {
        $metrics = [
            'today_revenue' => '0.0000',
            'total_assets' => '0.0000',
            'total_liabilities' => '0.0000',
            'net_profit_ytd' => '0.0000',
            'trial_balance_is_balanced' => true,
            'trial_balance_difference' => '0.0000',
        ];

        if ($this->reports !== null) {
            try {
                $metrics = $this->reports->dashboardMetrics();
            } catch (\Throwable) {
                // Keep dashboard available even if accounting migrations are not applied yet.
            }
        }

        $trialStatus = $metrics['trial_balance_is_balanced']
            ? 'Balanced'
            : 'Out of Balance (' . $metrics['trial_balance_difference'] . ')';

        return [
            'kpis' => [
                ['title' => 'Today Revenue', 'value' => (string) $metrics['today_revenue'], 'icon' => 'fa-chart-line'],
                ['title' => 'Total Assets', 'value' => (string) $metrics['total_assets'], 'icon' => 'fa-wallet'],
                ['title' => 'Total Liabilities', 'value' => (string) $metrics['total_liabilities'], 'icon' => 'fa-scale-balanced'],
                ['title' => 'Net Profit YTD', 'value' => (string) $metrics['net_profit_ytd'], 'icon' => 'fa-percent'],
                ['title' => 'Trial Balance Status', 'value' => $trialStatus, 'icon' => 'fa-shield-halved'],
            ],
            'trial_balance_status' => $trialStatus,
        ];
    }
}
