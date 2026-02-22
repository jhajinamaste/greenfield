<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Infrastructure\Repositories\Accounting\JournalEntryRepository;

final class LedgerService
{
    public function __construct(private readonly JournalEntryRepository $journals)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function accountLedger(
        int $accountId,
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $openingScaled = $this->toScaled($this->journals->sumAccountNetBeforeDate(
            $accountId,
            $fromDate,
            $branchId,
            $organizationId
        ));

        $rows = $this->journals->fetchAccountMovements(
            $accountId,
            $fromDate,
            $toDate,
            $branchId,
            $organizationId
        );

        $runningScaled = $openingScaled;
        $normalizedRows = [];
        foreach ($rows as $row) {
            $debitScaled = $this->toScaled((string) $row['debit_amount']);
            $creditScaled = $this->toScaled((string) $row['credit_amount']);
            $runningScaled += $debitScaled - $creditScaled;

            $normalizedRows[] = [
                'journal_entry_id' => (int) $row['journal_entry_id'],
                'line_id' => (int) $row['line_id'],
                'entry_date' => $row['entry_date'],
                'period_id' => $row['period_id'] !== null ? (int) $row['period_id'] : null,
                'reference_type' => $row['reference_type'],
                'reference_id' => $row['reference_id'],
                'description' => $row['description'],
                'debit_amount' => $this->toDecimal($debitScaled),
                'credit_amount' => $this->toDecimal($creditScaled),
                'running_balance' => $this->toDecimal($runningScaled),
            ];
        }

        return [
            'account_id' => $accountId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'opening_balance' => $this->toDecimal($openingScaled),
            'closing_balance' => $this->toDecimal($runningScaled),
            'rows' => $normalizedRows,
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
