<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Infrastructure\Repositories\Accounting\JournalEntryRepository;

final class FinancialReportService
{
    public function __construct(private readonly JournalEntryRepository $journals)
    {
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

        return [
            'as_of_date' => $asOfDate,
            'total_assets' => $this->toDecimal($totalAssetsScaled),
            'total_liabilities' => $this->toDecimal($totalLiabilitiesScaled),
            'total_equity' => $this->toDecimal($totalEquityScaled),
            'retained_earnings' => $this->toDecimal($retainedEarningsScaled),
            'liabilities_and_equity' => $this->toDecimal($liabilitiesAndEquityScaled),
            'balance_gap' => $this->toDecimal($totalAssetsScaled - $liabilitiesAndEquityScaled),
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

        return [
            'today_revenue' => $plToday['income_total'],
            'total_assets' => $balanceSheet['total_assets'],
            'total_liabilities' => $balanceSheet['total_liabilities'],
            'net_profit_ytd' => $plYtd['net_profit'],
            'trial_balance_is_balanced' => $trialBalance['is_balanced'],
            'trial_balance_difference' => $trialBalance['difference'],
        ];
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
