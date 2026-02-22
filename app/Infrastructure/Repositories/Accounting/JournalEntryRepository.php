<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Accounting;

use App\Infrastructure\Repositories\BaseRepository;

final class JournalEntryRepository extends BaseRepository
{
    public function createJournalEntry(array $entry, ?int $organizationId = null): int
    {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId(isset($entry['branch_id']) ? (int) $entry['branch_id'] : null);

        $sql = 'INSERT INTO journal_entries
                (organization_id, branch_id, entry_date, reference_type, reference_id, description, period_id, correlation_id, created_by)
                VALUES
                (:organization_id, :branch_id, :entry_date, :reference_type, :reference_id, :description, :period_id, :correlation_id, :created_by)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':branch_id' => $branchId,
            ':entry_date' => $entry['entry_date'],
            ':reference_type' => $entry['reference_type'],
            ':reference_id' => $entry['reference_id'] ?? null,
            ':description' => $entry['description'],
            ':period_id' => $entry['period_id'] ?? null,
            ':correlation_id' => $entry['correlation_id'] ?? null,
            ':created_by' => $entry['created_by'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function insertJournalLines(int $journalEntryId, array $lines): void
    {
        $sql = 'INSERT INTO journal_entry_lines
                (journal_entry_id, account_id, debit_amount, credit_amount)
                VALUES (:journal_entry_id, :account_id, :debit_amount, :credit_amount)';
        $stmt = $this->pdo->prepare($sql);

        foreach ($lines as $line) {
            $stmt->execute([
                ':journal_entry_id' => $journalEntryId,
                ':account_id' => (int) $line['account_id'],
                ':debit_amount' => $line['debit_amount'],
                ':credit_amount' => $line['credit_amount'],
            ]);
        }
    }

    public function fetchAccountMovements(
        int $accountId,
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $sql = 'SELECT
                    je.id AS journal_entry_id,
                    je.entry_date,
                    je.period_id,
                    je.reference_type,
                    je.reference_id,
                    je.description,
                    jel.id AS line_id,
                    jel.debit_amount,
                    jel.credit_amount
                FROM journal_entry_lines jel
                INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE je.organization_id = :org_je
                  AND coa.organization_id = :org_coa
                  AND jel.account_id = :account_id
                  AND je.entry_date BETWEEN :from_date AND :to_date';

        $params = [
            ':org_je' => $organizationId,
            ':org_coa' => $organizationId,
            ':account_id' => $accountId,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND je.branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $sql .= ' ORDER BY je.entry_date ASC, je.id ASC, jel.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function sumAccountNetBeforeDate(
        int $accountId,
        string $beforeDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): string {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $sql = 'SELECT
                    COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0) AS opening_balance
                FROM journal_entry_lines jel
                INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE je.organization_id = :org_je
                  AND coa.organization_id = :org_coa
                  AND jel.account_id = :account_id
                  AND je.entry_date < :before_date';

        $params = [
            ':org_je' => $organizationId,
            ':org_coa' => $organizationId,
            ':account_id' => $accountId,
            ':before_date' => $beforeDate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND je.branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch();
        return (string) ($row['opening_balance'] ?? '0');
    }

    public function fetchTrialBalanceRows(
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $sql = 'SELECT
                    coa.id AS account_id,
                    coa.code AS account_code,
                    coa.name AS account_name,
                    coa.type AS account_type,
                    COALESCE(SUM(jel.debit_amount), 0) AS total_debit,
                    COALESCE(SUM(jel.credit_amount), 0) AS total_credit,
                    COALESCE(SUM(jel.debit_amount - jel.credit_amount), 0) AS net_amount
                FROM journal_entry_lines jel
                INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE je.organization_id = :org_je
                  AND coa.organization_id = :org_coa
                  AND je.entry_date BETWEEN :from_date AND :to_date';

        $params = [
            ':org_je' => $organizationId,
            ':org_coa' => $organizationId,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND je.branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $sql .= ' GROUP BY coa.id, coa.code, coa.name, coa.type
                  ORDER BY coa.code ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function fetchTypeBalancesAsOf(
        string $asOfDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $sql = 'SELECT
                    coa.type AS account_type,
                    COALESCE(SUM(jel.debit_amount), 0) AS total_debit,
                    COALESCE(SUM(jel.credit_amount), 0) AS total_credit
                FROM journal_entry_lines jel
                INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE je.organization_id = :org_je
                  AND coa.organization_id = :org_coa
                  AND je.entry_date <= :as_of_date';

        $params = [
            ':org_je' => $organizationId,
            ':org_coa' => $organizationId,
            ':as_of_date' => $asOfDate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND je.branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $sql .= ' GROUP BY coa.type';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function fetchTypeBalancesBetween(
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $sql = 'SELECT
                    coa.type AS account_type,
                    COALESCE(SUM(jel.debit_amount), 0) AS total_debit,
                    COALESCE(SUM(jel.credit_amount), 0) AS total_credit
                FROM journal_entry_lines jel
                INNER JOIN journal_entries je ON je.id = jel.journal_entry_id
                INNER JOIN chart_of_accounts coa ON coa.id = jel.account_id
                WHERE je.organization_id = :org_je
                  AND coa.organization_id = :org_coa
                  AND je.entry_date BETWEEN :from_date AND :to_date';

        $params = [
            ':org_je' => $organizationId,
            ':org_coa' => $organizationId,
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND je.branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $sql .= ' GROUP BY coa.type';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
