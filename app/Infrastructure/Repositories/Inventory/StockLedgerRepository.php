<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Inventory;

use App\Infrastructure\Repositories\BaseRepository;

final class StockLedgerRepository extends BaseRepository
{
    // Append-only repository: stock_ledger updates/deletes are blocked at DB trigger level.
    public function createMovement(array $movement, ?int $organizationId = null): int
    {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId((int) $movement['branch_id']) ?? (int) $movement['branch_id'];

        $sql = 'INSERT INTO stock_ledger
                (organization_id, branch_id, product_id, location_id, movement_type, quantity, unit_cost, reference_type, reference_id, correlation_id, entry_date)
                VALUES
                (:organization_id, :branch_id, :product_id, :location_id, :movement_type, :quantity, :unit_cost, :reference_type, :reference_id, :correlation_id, :entry_date)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':branch_id' => $branchId,
            ':product_id' => (int) $movement['product_id'],
            ':location_id' => (int) $movement['location_id'],
            ':movement_type' => (string) $movement['movement_type'],
            ':quantity' => (string) $movement['quantity'],
            ':unit_cost' => (string) $movement['unit_cost'],
            ':reference_type' => (string) $movement['reference_type'],
            ':reference_id' => $movement['reference_id'] ?? null,
            ':correlation_id' => (string) $movement['correlation_id'],
            ':entry_date' => (string) $movement['entry_date'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id, ?int $organizationId = null): ?array
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id, organization_id, branch_id, product_id, location_id, movement_type, quantity, unit_cost, reference_type, reference_id, correlation_id, entry_date, created_at
                FROM stock_ledger
                WHERE id = :id
                  AND organization_id = :organization_id
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':organization_id' => $organizationId,
        ]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function countByReference(string $referenceType, string $referenceId, ?int $organizationId = null): int
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT COUNT(*) AS row_count
                FROM stock_ledger
                WHERE organization_id = :organization_id
                  AND reference_type = :reference_type
                  AND reference_id = :reference_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':reference_type' => $referenceType,
            ':reference_id' => $referenceId,
        ]);

        return (int) ($stmt->fetch()['row_count'] ?? 0);
    }

    public function existsCorrelationId(string $correlationId, ?int $organizationId = null): bool
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT id
                FROM stock_ledger
                WHERE organization_id = :organization_id
                  AND correlation_id = :correlation_id
                LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':correlation_id' => $correlationId,
        ]);

        return (bool) $stmt->fetch();
    }

    /**
     * @return array<int, string>
     */
    public function fetchAccountingImpactCorrelationIds(
        ?int $branchId = null,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $sql = 'SELECT DISTINCT correlation_id
                FROM stock_ledger
                WHERE organization_id = :organization_id
                  AND movement_type IN (\'purchase\', \'sale\', \'adjustment\', \'transfer_in\', \'transfer_out\')
                  AND correlation_id <> \'\'';
        $params = [
            ':organization_id' => $organizationId,
        ];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $sql .= ' ORDER BY correlation_id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): string => (string) $row['correlation_id'], $rows);
    }

    /**
     * @return array<int, array{product_id:int, quantity:string}>
     */
    public function fetchNegativeInventoryBalances(
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);

        $sql = 'SELECT
                    product_id,
                    COALESCE(SUM(
                        CASE
                            WHEN movement_type IN (\'purchase\', \'transfer_in\') THEN quantity
                            WHEN movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -quantity
                            ELSE 0
                        END
                    ), 0) AS quantity
                FROM stock_ledger
                WHERE organization_id = :organization_id
                GROUP BY product_id
                HAVING quantity < 0
                ORDER BY product_id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
        ]);
        $rows = $stmt->fetchAll();

        return array_map(static fn(array $row): array => [
            'product_id' => (int) $row['product_id'],
            'quantity' => (string) $row['quantity'],
        ], $rows);
    }

    /**
     * Weighted-average costing scope is organization + product.
     * Branch-level slicing is intentionally excluded to keep a single org-wide cost basis per item.
     *
     * @return array{quantity:string,value:string}
     */
    public function aggregateProductOrganizationBalance(
        int $productId,
        string $asOfDate,
        bool $includeTransit = false,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);

        $sql = 'SELECT
                    COALESCE(SUM(
                        CASE
                            WHEN sl.movement_type IN (\'purchase\', \'transfer_in\') THEN sl.quantity
                            WHEN sl.movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -sl.quantity
                            ELSE 0
                        END
                    ), 0) AS quantity,
                    COALESCE(SUM(
                        CASE
                            WHEN sl.movement_type IN (\'purchase\', \'transfer_in\') THEN sl.quantity * sl.unit_cost
                            WHEN sl.movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -sl.quantity * sl.unit_cost
                            ELSE 0
                        END
                    ), 0) AS value
                FROM stock_ledger sl
                INNER JOIN inventory_locations il ON il.id = sl.location_id
                INNER JOIN products p ON p.id = sl.product_id
                WHERE sl.organization_id = :organization_id
                  AND il.organization_id = :organization_id_location
                  AND p.organization_id = :organization_id_product
                  AND sl.product_id = :product_id
                  AND sl.entry_date <= :as_of_date
                  AND p.type = :product_type';

        $params = [
            ':organization_id' => $organizationId,
            ':organization_id_location' => $organizationId,
            ':organization_id_product' => $organizationId,
            ':product_id' => $productId,
            ':as_of_date' => $asOfDate,
            ':product_type' => 'inventory',
        ];

        if (!$includeTransit) {
            $sql .= ' AND il.type <> :transit_type';
            $params[':transit_type'] = 'transit';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['quantity' => '0', 'value' => '0'];

        return [
            'quantity' => (string) $row['quantity'],
            'value' => (string) $row['value'],
        ];
    }

    /**
     * @return array{quantity:string,value:string}
     */
    public function aggregateProductBranchBalance(
        int $productId,
        int $branchId,
        string $asOfDate,
        bool $includeTransit = false,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId) ?? $branchId;

        $sql = 'SELECT
                    COALESCE(SUM(
                        CASE
                            WHEN sl.movement_type IN (\'purchase\', \'transfer_in\') THEN sl.quantity
                            WHEN sl.movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -sl.quantity
                            ELSE 0
                        END
                    ), 0) AS quantity,
                    COALESCE(SUM(
                        CASE
                            WHEN sl.movement_type IN (\'purchase\', \'transfer_in\') THEN sl.quantity * sl.unit_cost
                            WHEN sl.movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -sl.quantity * sl.unit_cost
                            ELSE 0
                        END
                    ), 0) AS value
                FROM stock_ledger sl
                INNER JOIN inventory_locations il ON il.id = sl.location_id
                INNER JOIN products p ON p.id = sl.product_id
                WHERE sl.organization_id = :organization_id
                  AND il.organization_id = :organization_id_location
                  AND p.organization_id = :organization_id_product
                  AND sl.product_id = :product_id
                  AND sl.branch_id = :branch_id
                  AND sl.entry_date <= :as_of_date
                  AND p.type = :product_type';

        $params = [
            ':organization_id' => $organizationId,
            ':organization_id_location' => $organizationId,
            ':organization_id_product' => $organizationId,
            ':product_id' => $productId,
            ':branch_id' => $branchId,
            ':as_of_date' => $asOfDate,
            ':product_type' => 'inventory',
        ];

        if (!$includeTransit) {
            $sql .= ' AND il.type <> :transit_type';
            $params[':transit_type'] = 'transit';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['quantity' => '0', 'value' => '0'];

        return [
            'quantity' => (string) $row['quantity'],
            'value' => (string) $row['value'],
        ];
    }

    /**
     * @return array{quantity:string,value:string}
     */
    public function aggregateProductLocationBalance(
        int $productId,
        int $locationId,
        string $asOfDate,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);

        $sql = 'SELECT
                    COALESCE(SUM(
                        CASE
                            WHEN movement_type IN (\'purchase\', \'transfer_in\') THEN quantity
                            WHEN movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -quantity
                            ELSE 0
                        END
                    ), 0) AS quantity,
                    COALESCE(SUM(
                        CASE
                            WHEN movement_type IN (\'purchase\', \'transfer_in\') THEN quantity * unit_cost
                            WHEN movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -quantity * unit_cost
                            ELSE 0
                        END
                    ), 0) AS value
                FROM stock_ledger
                WHERE organization_id = :organization_id
                  AND product_id = :product_id
                  AND location_id = :location_id
                  AND entry_date <= :as_of_date';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':product_id' => $productId,
            ':location_id' => $locationId,
            ':as_of_date' => $asOfDate,
        ]);
        $row = $stmt->fetch() ?: ['quantity' => '0', 'value' => '0'];

        return [
            'quantity' => (string) $row['quantity'],
            'value' => (string) $row['value'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function inventoryValuationRows(
        string $asOfDate,
        ?int $branchId = null,
        bool $includeTransit = false,
        ?int $organizationId = null
    ): array {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $sql = 'SELECT
                    sl.product_id,
                    p.sku,
                    p.name AS product_name,
                    sl.branch_id,
                    COALESCE(SUM(
                        CASE
                            WHEN sl.movement_type IN (\'purchase\', \'transfer_in\') THEN sl.quantity
                            WHEN sl.movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -sl.quantity
                            ELSE 0
                        END
                    ), 0) AS quantity,
                    COALESCE(SUM(
                        CASE
                            WHEN sl.movement_type IN (\'purchase\', \'transfer_in\') THEN sl.quantity * sl.unit_cost
                            WHEN sl.movement_type IN (\'sale\', \'adjustment\', \'transfer_out\') THEN -sl.quantity * sl.unit_cost
                            ELSE 0
                        END
                    ), 0) AS value
                FROM stock_ledger sl
                INNER JOIN products p ON p.id = sl.product_id
                INNER JOIN inventory_locations il ON il.id = sl.location_id
                WHERE sl.organization_id = :organization_id
                  AND p.organization_id = :organization_id_product
                  AND il.organization_id = :organization_id_location
                  AND p.type = :product_type
                  AND sl.entry_date <= :as_of_date';

        $params = [
            ':organization_id' => $organizationId,
            ':organization_id_product' => $organizationId,
            ':organization_id_location' => $organizationId,
            ':product_type' => 'inventory',
            ':as_of_date' => $asOfDate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND sl.branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        if (!$includeTransit) {
            $sql .= ' AND il.type <> :transit_type';
            $params[':transit_type'] = 'transit';
        }

        $sql .= ' GROUP BY sl.product_id, p.sku, p.name, sl.branch_id
                  ORDER BY p.sku ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function cogsTotalFromStock(
        string $fromDate,
        string $toDate,
        ?int $branchId = null,
        ?int $organizationId = null
    ): string {
        $organizationId = $this->organizationId($organizationId);
        $branchId = $this->branchId($branchId);

        $sql = 'SELECT COALESCE(SUM(quantity * unit_cost), 0) AS cogs_total
                FROM stock_ledger
                WHERE organization_id = :organization_id
                  AND movement_type = :movement_type
                  AND entry_date BETWEEN :from_date AND :to_date';

        $params = [
            ':organization_id' => $organizationId,
            ':movement_type' => 'sale',
            ':from_date' => $fromDate,
            ':to_date' => $toDate,
        ];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = :branch_id';
            $params[':branch_id'] = $branchId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return (string) ($row['cogs_total'] ?? '0');
    }
}
