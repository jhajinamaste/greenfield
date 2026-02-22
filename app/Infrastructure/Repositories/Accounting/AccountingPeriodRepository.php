<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Accounting;

use App\Domain\Accounting\AccountingPeriodViolationException;
use App\Infrastructure\Repositories\BaseRepository;

final class AccountingPeriodRepository extends BaseRepository
{
    public function findClosedPeriodByDate(string $entryDate, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, period_start, period_end, status, closed_at, closed_by
                FROM accounting_periods
                WHERE organization_id = :organization_id
                  AND status = :status
                  AND :entry_date BETWEEN period_start AND period_end
                ORDER BY period_start DESC
                LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':status' => 'closed',
            ':entry_date' => $entryDate,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findLatestClosedPeriod(?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, period_start, period_end, status, closed_at, closed_by
                FROM accounting_periods
                WHERE organization_id = :organization_id
                  AND status = :status
                ORDER BY period_end DESC, id DESC
                LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':status' => 'closed',
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findOpenPeriodByDate(string $entryDate, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, period_start, period_end, status
                FROM accounting_periods
                WHERE organization_id = :organization_id
                  AND status = :status
                  AND :entry_date BETWEEN period_start AND period_end
                ORDER BY period_start DESC
                LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':status' => 'open',
            ':entry_date' => $entryDate,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $periodId, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, period_start, period_end, status, closed_at, closed_by
                FROM accounting_periods
                WHERE id = :id AND organization_id = :organization_id
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $periodId,
            ':organization_id' => $organizationId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lockPeriodForUpdate(int $periodId, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, period_start, period_end, status, closed_at, closed_by
                FROM accounting_periods
                WHERE id = :id AND organization_id = :organization_id
                FOR UPDATE';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $periodId,
            ':organization_id' => $organizationId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function hasOverlappingPeriods(
        string $periodStart,
        string $periodEnd,
        ?int $organizationId = null,
        ?string $status = null,
        ?int $excludePeriodId = null
    ): bool {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id
                FROM accounting_periods
                WHERE organization_id = :organization_id
                  AND NOT (period_end < :period_start OR period_start > :period_end)';
        $params = [
            ':organization_id' => $organizationId,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
        ];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        if ($excludePeriodId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludePeriodId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }

    public function hasFutureOpenPeriods(string $afterDate, ?int $organizationId = null): bool
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id
                FROM accounting_periods
                WHERE organization_id = :organization_id
                  AND status = :status
                  AND period_start > :after_date
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':status' => 'open',
            ':after_date' => $afterDate,
        ]);

        return (bool) $stmt->fetch();
    }

    public function trialBalanceDifferenceForRange(
        string $periodStart,
        string $periodEnd,
        ?int $organizationId = null
    ): string {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT
                    COALESCE(SUM(jel.debit_amount), 0) AS total_debit,
                    COALESCE(SUM(jel.credit_amount), 0) AS total_credit
                FROM journal_entries je
                INNER JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id
                WHERE je.organization_id = :organization_id
                  AND je.entry_date BETWEEN :period_start AND :period_end';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
        ]);

        $row = $stmt->fetch() ?: ['total_debit' => '0', 'total_credit' => '0'];
        $debitScaled = (int) round(((float) $row['total_debit']) * 10000);
        $creditScaled = (int) round(((float) $row['total_credit']) * 10000);

        return number_format(($debitScaled - $creditScaled) / 10000, 4, '.', '');
    }

    public function createOpenPeriod(
        string $periodStart,
        string $periodEnd,
        ?int $organizationId = null
    ): int {
        $organizationId = $this->organizationId($organizationId);
        if ($periodStart > $periodEnd) {
            throw new AccountingPeriodViolationException([
                'code' => 'invalid_period_range',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ], 'Period start cannot be after period end.');
        }

        if ($this->hasOverlappingPeriods($periodStart, $periodEnd, $organizationId)) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_overlap_detected',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ], 'Accounting period overlaps an existing period.');
        }

        if ($this->hasOverlappingPeriods($periodStart, $periodEnd, $organizationId, 'open')) {
            throw new AccountingPeriodViolationException([
                'code' => 'open_period_overlap_detected',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ], 'Cannot create overlapping open accounting periods.');
        }

        $sql = 'INSERT INTO accounting_periods (organization_id, period_start, period_end, status)
                VALUES (:organization_id, :period_start, :period_end, :status)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
            ':status' => 'open',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function closePeriod(int $periodId, int $closedBy, ?int $organizationId = null): void
    {
        $organizationId = $this->organizationId($organizationId);
        $period = $this->findById($periodId, $organizationId);
        if ($period === null) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_not_found',
                'period_id' => $periodId,
            ], 'Accounting period does not exist.');
        }

        if ((string) $period['status'] !== 'open') {
            throw new AccountingPeriodViolationException([
                'code' => 'period_not_open',
                'period_id' => $periodId,
                'status' => $period['status'],
            ], 'Only open periods can be closed.');
        }

        if ($this->hasFutureOpenPeriods((string) $period['period_end'], $organizationId)) {
            throw new AccountingPeriodViolationException([
                'code' => 'future_open_period_exists',
                'period_id' => $periodId,
                'period_end' => $period['period_end'],
            ], 'Cannot close period while a future open period exists.');
        }

        $difference = $this->trialBalanceDifferenceForRange((string) $period['period_start'], (string) $period['period_end'], $organizationId);
        if ((float) $difference !== 0.0) {
            throw new AccountingPeriodViolationException([
                'code' => 'trial_balance_not_zero',
                'period_id' => $periodId,
                'difference' => $difference,
            ], 'Cannot close period because trial balance is not zero.');
        }

        $sql = 'UPDATE accounting_periods
                SET status = :status, closed_at = CURRENT_TIMESTAMP, closed_by = :closed_by
                WHERE id = :id
                  AND organization_id = :organization_id
                  AND status = :open_status';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':status' => 'closed',
            ':closed_by' => $closedBy,
            ':id' => $periodId,
            ':organization_id' => $organizationId,
            ':open_status' => 'open',
        ]);

        if ($stmt->rowCount() === 0) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_close_failed',
                'period_id' => $periodId,
            ], 'Accounting period close failed.');
        }
    }
}
