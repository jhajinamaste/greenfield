<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Inventory;

use App\Infrastructure\Repositories\BaseRepository;

final class InventoryLocationRepository extends BaseRepository
{
    public function create(
        int $branchId,
        string $name,
        string $type = 'store',
        ?int $organizationId = null
    ): int {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId) ?? $branchId;

        $sql = 'INSERT INTO inventory_locations (organization_id, branch_id, name, type)
                VALUES (:organization_id, :branch_id, :name, :type)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':branch_id' => $branchId,
            ':name' => $name,
            ':type' => $type,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $locationId, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, branch_id, name, type
                FROM inventory_locations
                WHERE id = :id AND organization_id = :organization_id
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $locationId,
            ':organization_id' => $organizationId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }
}
