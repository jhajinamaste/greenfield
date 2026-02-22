<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Accounting;

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
}
