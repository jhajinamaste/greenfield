<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Infrastructure\Repositories\Inventory\StockLedgerRepository;

final class InventoryCostingService
{
    public function __construct(private readonly StockLedgerRepository $stockLedger)
    {
    }

    /**
     * Costing scope invariant:
     * Weighted-average cost is calculated per organization + product across all branches.
     * The branch argument is kept for compatibility but does not change the WA basis.
     *
     * @return array{quantity:string,value:string,average_cost:string}
     */
    public function snapshot(
        int $productId,
        int $branchId,
        string $asOfDate,
        ?int $organizationId = null,
        bool $includeTransit = false
    ): array {
        $balance = $this->stockLedger->aggregateProductOrganizationBalance(
            $productId,
            $asOfDate,
            $includeTransit,
            $organizationId
        );

        $quantityScaled = $this->toScaled($balance['quantity']);
        $valueScaled = $this->toScaled($balance['value']);
        $averageScaled = $quantityScaled > 0
            ? (int) round(($valueScaled * 10000) / $quantityScaled)
            : 0;

        return [
            'quantity' => $this->toDecimal($quantityScaled),
            'value' => $this->toDecimal($valueScaled),
            'average_cost' => $this->toDecimal($averageScaled),
        ];
    }

    public function ensureLocationStockAvailable(
        int $productId,
        int $locationId,
        string $asOfDate,
        string $requiredQuantity,
        ?int $organizationId = null
    ): void {
        $requiredScaled = $this->toScaled($requiredQuantity);
        if ($requiredScaled <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_required_quantity',
                'required_quantity' => $requiredQuantity,
            ], 'Required quantity must be greater than zero.');
        }

        $balance = $this->stockLedger->aggregateProductLocationBalance(
            $productId,
            $locationId,
            $asOfDate,
            $organizationId
        );

        $availableScaled = $this->toScaled($balance['quantity']);
        if ($availableScaled < $requiredScaled) {
            throw new InventoryValidationException([
                'code' => 'insufficient_stock',
                'product_id' => $productId,
                'location_id' => $locationId,
                'required_quantity' => $this->toDecimal($requiredScaled),
                'available_quantity' => $this->toDecimal($availableScaled),
            ], 'Insufficient stock for requested movement.');
        }
    }

    /**
     * @return array{unit_cost:string,total_value:string}
     */
    public function saleCost(
        int $productId,
        int $branchId,
        string $quantity,
        string $entryDate,
        ?int $organizationId = null
    ): array {
        $quantityScaled = $this->toScaled($quantity);
        if ($quantityScaled <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_sale_quantity',
                'quantity' => $quantity,
            ], 'Sale quantity must be positive.');
        }

        $snapshot = $this->snapshot($productId, $branchId, $entryDate, $organizationId);
        $avgCostScaled = $this->toScaled($snapshot['average_cost']);
        $cogsScaled = (int) round(($quantityScaled * $avgCostScaled) / 10000);

        return [
            'unit_cost' => $this->toDecimal($avgCostScaled),
            'total_value' => $this->toDecimal($cogsScaled),
        ];
    }

    /**
     * @return array{average_cost:string,new_quantity:string,new_value:string}
     */
    public function purchaseCost(
        int $productId,
        int $branchId,
        string $quantity,
        string $unitCost,
        string $entryDate,
        ?int $organizationId = null
    ): array {
        $quantityScaled = $this->toScaled($quantity);
        $unitCostScaled = $this->toScaled($unitCost);
        if ($quantityScaled <= 0 || $unitCostScaled <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_purchase_values',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ], 'Purchase quantity and unit cost must be positive.');
        }

        $snapshot = $this->snapshot($productId, $branchId, $entryDate, $organizationId);
        $existingQtyScaled = $this->toScaled($snapshot['quantity']);
        $existingValueScaled = $this->toScaled($snapshot['value']);

        $incomingValueScaled = (int) round(($quantityScaled * $unitCostScaled) / 10000);
        $newQtyScaled = $existingQtyScaled + $quantityScaled;
        $newValueScaled = $existingValueScaled + $incomingValueScaled;
        $avgScaled = $newQtyScaled > 0
            ? (int) round(($newValueScaled * 10000) / $newQtyScaled)
            : 0;

        return [
            'average_cost' => $this->toDecimal($avgScaled),
            'new_quantity' => $this->toDecimal($newQtyScaled),
            'new_value' => $this->toDecimal($newValueScaled),
        ];
    }

    private function toScaled(string $amount): int
    {
        return (int) round(((float) $amount) * 10000);
    }

    private function toDecimal(int $amount): string
    {
        return number_format($amount / 10000, 4, '.', '');
    }
}
