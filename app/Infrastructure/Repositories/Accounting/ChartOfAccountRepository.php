<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Accounting;

use App\Domain\Accounting\InvariantViolationException;
use App\Infrastructure\Repositories\BaseRepository;

final class ChartOfAccountRepository extends BaseRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findActiveByIds(array $accountIds, ?int $organizationId = null): array
    {
        $organizationId = $this->organizationId($organizationId);
        $accountIds = array_values(array_unique(array_map(static fn(mixed $id): int => (int) $id, $accountIds)));
        if ($accountIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':organization_id' => $organizationId,
            ':is_active' => 1,
        ];
        foreach ($accountIds as $index => $id) {
            $key = ':id_' . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        $sql = sprintf(
            'SELECT id, organization_id, code, name, type, is_system_account, is_active
             FROM chart_of_accounts
             WHERE organization_id = :organization_id
               AND is_active = :is_active
               AND id IN (%s)',
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    public function findByCode(string $code, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, code, name, type, is_system_account, is_active
                FROM chart_of_accounts
                WHERE organization_id = :organization_id
                  AND code = :code
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':code' => $code,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $accountId, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, code, name, type, is_system_account, is_active
                FROM chart_of_accounts
                WHERE id = :id
                  AND organization_id = :organization_id
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $accountId,
            ':organization_id' => $organizationId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateAccount(
        int $accountId,
        string $code,
        string $name,
        string $type,
        bool $isActive,
        ?int $organizationId = null
    ): void {
        $organizationId = $this->organizationId($organizationId);
        $current = $this->findById($accountId, $organizationId);
        if ($current === null) {
            throw new InvariantViolationException([
                'code' => 'account_not_found',
                'account_id' => $accountId,
            ], 'Account does not exist in this organization.');
        }

        $isSystemAccount = (int) $current['is_system_account'] === 1;
        if ($isSystemAccount) {
            if ((string) $current['code'] !== $code) {
                throw new InvariantViolationException([
                    'code' => 'system_account_code_immutable',
                    'account_id' => $accountId,
                    'current_code' => (string) $current['code'],
                    'attempted_code' => $code,
                ], 'System account code cannot be modified.');
            }

            if ((string) $current['type'] !== $type) {
                throw new InvariantViolationException([
                    'code' => 'system_account_type_immutable',
                    'account_id' => $accountId,
                    'current_type' => (string) $current['type'],
                    'attempted_type' => $type,
                ], 'System account type cannot be modified.');
            }
        }

        $sql = 'UPDATE chart_of_accounts
                SET code = :code, name = :name, type = :type, is_active = :is_active
                WHERE id = :id AND organization_id = :organization_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':code' => $code,
            ':name' => $name,
            ':type' => $type,
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $accountId,
            ':organization_id' => $organizationId,
        ]);
    }

    public function deleteAccount(int $accountId, ?int $organizationId = null): void
    {
        $organizationId = $this->organizationId($organizationId);
        $current = $this->findById($accountId, $organizationId);
        if ($current === null) {
            return;
        }

        if ((int) $current['is_system_account'] === 1) {
            throw new InvariantViolationException([
                'code' => 'system_account_delete_blocked',
                'account_id' => $accountId,
            ], 'System account cannot be deleted.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM chart_of_accounts WHERE id = :id AND organization_id = :organization_id');
        $stmt->execute([
            ':id' => $accountId,
            ':organization_id' => $organizationId,
        ]);
    }
}
