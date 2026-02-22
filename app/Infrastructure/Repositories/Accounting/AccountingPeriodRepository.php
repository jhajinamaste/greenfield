<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Accounting;

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

    public function createOpenPeriod(
        string $periodStart,
        string $periodEnd,
        ?int $organizationId = null
    ): int {
        $organizationId = $this->organizationId($organizationId);
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
            throw new \RuntimeException('Accounting period close failed or period is already closed.');
        }
    }
}
