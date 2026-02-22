<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Inventory;

use App\Infrastructure\Repositories\BaseRepository;

final class ProductRepository extends BaseRepository
{
    public function create(
        string $sku,
        string $name,
        string $type = 'inventory',
        string $costingMethod = 'weighted_avg',
        bool $isActive = true,
        ?int $organizationId = null
    ): int {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'INSERT INTO products
                (organization_id, sku, name, type, costing_method, is_active)
                VALUES (:organization_id, :sku, :name, :type, :costing_method, :is_active)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':sku' => $sku,
            ':name' => $name,
            ':type' => $type,
            ':costing_method' => $costingMethod,
            ':is_active' => $isActive ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $productId, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, sku, name, type, costing_method, is_active
                FROM products
                WHERE id = :id AND organization_id = :organization_id
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $productId,
            ':organization_id' => $organizationId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findBySku(string $sku, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, sku, name, type, costing_method, is_active
                FROM products
                WHERE sku = :sku AND organization_id = :organization_id
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':sku' => $sku,
            ':organization_id' => $organizationId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }
}
