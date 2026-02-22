<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Tenant;

use App\Infrastructure\Repositories\BaseRepository;

final class BranchRepository extends BaseRepository
{
    public function isActiveInOrganization(int $branchId, ?int $organizationId = null): bool
    {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId) ?? $branchId;

        $sql = 'SELECT id FROM branches
                WHERE id = :id
                  AND organization_id = :organization_id
                  AND status = :status
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $branchId,
            ':organization_id' => $organizationId,
            ':status' => 'active',
        ]);

        return (bool) $stmt->fetch();
    }
}
