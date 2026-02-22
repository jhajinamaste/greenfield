<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Auth;

use App\Infrastructure\Repositories\BaseRepository;

final class UserRepository extends BaseRepository
{
    public function findByUsernameForTenant(string $username, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId();
        $sql = 'SELECT id, organization_id, branch_id, username, email, password_hash, status
                FROM users
                WHERE organization_id = :org_id
                  AND username = :username
                  AND status = :status
                  AND branch_id IN (
                    SELECT uba.branch_id
                    FROM user_branch_access uba
                    WHERE uba.user_id = users.id
                      AND uba.organization_id = :org_id_access
                  )';

        $params = [
            ':username' => $username,
            ':status' => 'active',
            ':org_id' => $organizationId,
            ':org_id_access' => $organizationId,
        ];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findRoles(int $userId, ?int $organizationId = null, ?int $branchId = null): array
    {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $globalSql = 'SELECT DISTINCT r.code
                      FROM roles r
                      INNER JOIN user_roles ur ON ur.role_id = r.id
                      WHERE ur.user_id = :global_user_id
                        AND ur.organization_id = :global_org_id';
        $globalStmt = $this->pdo->prepare($globalSql);
        $globalStmt->execute([
            ':global_user_id' => $userId,
            ':global_org_id' => $organizationId,
        ]);
        $codes = array_map(static fn(array $row): string => (string) $row['code'], $globalStmt->fetchAll());

        if ($branchId !== null) {
            $branchSql = 'SELECT DISTINCT r2.code
                          FROM roles r2
                          INNER JOIN user_branch_roles ubr ON ubr.role_id = r2.id
                          WHERE ubr.user_id = :branch_user_id
                            AND ubr.organization_id = :branch_org_id
                            AND ubr.branch_id = :branch_id';
            $branchStmt = $this->pdo->prepare($branchSql);
            $branchStmt->execute([
                ':branch_user_id' => $userId,
                ':branch_org_id' => $organizationId,
                ':branch_id' => $branchId,
            ]);
            $branchCodes = array_map(static fn(array $row): string => (string) $row['code'], $branchStmt->fetchAll());
            $codes = array_merge($codes, $branchCodes);
        }

        return array_values(array_unique($codes));
    }
}
