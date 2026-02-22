<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Infrastructure\Repositories\Accounting\AccountingPeriodRepository;

final class AccountingPeriodGuard
{
    public function __construct(
        private readonly AccountingPeriodRepository $periods,
        private readonly ?FinancialReportService $reports = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function assertMutationAllowed(
        \DateTimeInterface|string $entryDate,
        string $mutationType,
        ?int $organizationId = null
    ): array {
        $date = $this->normalizeDate($entryDate);

        $closed = $this->periods->findClosedPeriodByDate($date, $organizationId);
        if ($closed !== null) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_closed',
                'mutation_type' => $mutationType,
                'entry_date' => $date,
                'period_id' => (int) $closed['id'],
                'period_start' => $closed['period_start'],
                'period_end' => $closed['period_end'],
            ], 'Mutation blocked because the accounting period is closed.');
        }

        $open = $this->periods->findOpenPeriodByDate($date, $organizationId);
        if ($open === null) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_not_open',
                'mutation_type' => $mutationType,
                'entry_date' => $date,
            ], 'Mutation blocked because no open accounting period exists for this date.');
        }

        return $open;
    }

    /**
     * @return array<string, mixed>
     */
    public function lockOpenPeriodForPosting(
        int $periodId,
        \DateTimeInterface|string $entryDate,
        ?int $organizationId = null
    ): array {
        $date = $this->normalizeDate($entryDate);
        $period = $this->periods->lockPeriodForUpdate($periodId, $organizationId);
        if ($period === null) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_not_found',
                'period_id' => $periodId,
                'entry_date' => $date,
            ], 'Posting blocked because the accounting period was not found.');
        }

        if ((string) $period['status'] !== 'open') {
            throw new AccountingPeriodViolationException([
                'code' => 'period_closed_race',
                'period_id' => $periodId,
                'entry_date' => $date,
                'status' => $period['status'],
            ], 'Posting blocked because the accounting period status changed during processing.');
        }

        if (!($date >= (string) $period['period_start'] && $date <= (string) $period['period_end'])) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_date_mismatch',
                'period_id' => $periodId,
                'entry_date' => $date,
                'period_start' => $period['period_start'],
                'period_end' => $period['period_end'],
            ], 'Posting blocked because entry date no longer maps to the locked period.');
        }

        return $period;
    }

    public function assertPeriodCreationAllowed(
        \DateTimeInterface|string $periodStart,
        \DateTimeInterface|string $periodEnd,
        ?int $organizationId = null
    ): void {
        $start = $this->normalizeDate($periodStart);
        $end = $this->normalizeDate($periodEnd);

        if ($start > $end) {
            throw new AccountingPeriodViolationException([
                'code' => 'invalid_period_range',
                'period_start' => $start,
                'period_end' => $end,
            ], 'Period start cannot be after period end.');
        }

        if ($this->periods->hasOverlappingPeriods($start, $end, $organizationId)) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_overlap_detected',
                'period_start' => $start,
                'period_end' => $end,
            ], 'Period creation blocked due to overlap.');
        }

        if ($this->periods->hasOverlappingPeriods($start, $end, $organizationId, 'open')) {
            throw new AccountingPeriodViolationException([
                'code' => 'open_period_overlap_detected',
                'period_start' => $start,
                'period_end' => $end,
            ], 'Period creation blocked due to overlapping open period.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function assertPeriodCloseAllowed(int $periodId, ?int $organizationId = null): array
    {
        $period = $this->periods->findById($periodId, $organizationId);
        if ($period === null) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_not_found',
                'period_id' => $periodId,
            ], 'Period close blocked because period was not found.');
        }

        if ((string) $period['status'] !== 'open') {
            throw new AccountingPeriodViolationException([
                'code' => 'period_not_open',
                'period_id' => $periodId,
                'status' => $period['status'],
            ], 'Period close blocked because period is not open.');
        }

        if ($this->periods->hasFutureOpenPeriods((string) $period['period_end'], $organizationId)) {
            throw new AccountingPeriodViolationException([
                'code' => 'future_open_period_exists',
                'period_id' => $periodId,
                'period_end' => $period['period_end'],
            ], 'Period close blocked because a future open period exists.');
        }

        if ($this->reports !== null) {
            $trialBalance = $this->reports->trialBalance((string) $period['period_start'], (string) $period['period_end'], null, $organizationId);
            if (!($trialBalance['is_balanced'] ?? false)) {
                throw new AccountingPeriodViolationException([
                    'code' => 'trial_balance_not_zero',
                    'period_id' => $periodId,
                    'difference' => $trialBalance['difference'] ?? null,
                ], 'Period close blocked because trial balance is not zero.');
            }
        }

        return $period;
    }

    private function normalizeDate(\DateTimeInterface|string $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : (new \DateTimeImmutable($value))->format('Y-m-d');
    }
}
