<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Core\Database\TransactionManager;
use App\Domain\Accounting\AccountingPeriodGuard;
use App\Domain\Accounting\InvariantViolationException;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Audit\AuditLogService;
use App\Infrastructure\Repositories\Accounting\AccountingPeriodRepository;
use App\Infrastructure\Repositories\Accounting\ChartOfAccountRepository;
use App\Infrastructure\Repositories\Inventory\InventoryLocationRepository;
use App\Infrastructure\Repositories\Inventory\ProductRepository;
use App\Infrastructure\Repositories\Inventory\StockLedgerRepository;
use App\Infrastructure\Repositories\Tenant\BranchRepository;

final class InventoryPostingService
{
    private const ACC_INVENTORY = '1300';
    private const ACC_COGS = '5200';
    private const ACC_PURCHASE_CLEARING = '2200';
    private const ACC_ACCOUNTS_PAYABLE = '2000';
    private const ACC_STOCK_ADJUSTMENT = '5201';
    private const ACC_STOCK_IN_TRANSIT = '1305';

    /** @var array<string, int> */
    private array $accountIdCache = [];

    public function __construct(
        private readonly TransactionManager $tx,
        private readonly InventoryCostingService $costing,
        private readonly StockLedgerRepository $stockLedger,
        private readonly ProductRepository $products,
        private readonly InventoryLocationRepository $locations,
        private readonly BranchRepository $branches,
        private readonly AccountingPeriodGuard $periodGuard,
        private readonly AccountingPeriodRepository $periods,
        private readonly JournalPostingService $journals,
        private readonly ChartOfAccountRepository $accounts,
        private readonly AuditLogService $audit
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{stock_ledger_id:int,journal_entry_id:int,correlation_id:string}
     */
    public function purchaseReceive(array $payload): array
    {
        $ctx = $this->validateCommonPayload($payload, true);
        $this->assertNotBeforeLatestClosedPeriod($ctx['entry_date'], $ctx['organization_id']);
        $this->periodGuard->assertMutationAllowed($ctx['entry_date'], 'inventory.purchase', $ctx['organization_id']);

        $qtyScaled = $this->toScaled($ctx['quantity']);
        $unitCostScaled = $this->toScaled($ctx['unit_cost']);
        if ($qtyScaled <= 0 || $unitCostScaled <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_purchase_quantity_or_cost',
                'quantity' => $ctx['quantity'],
                'unit_cost' => $ctx['unit_cost'],
            ], 'Purchase requires positive quantity and unit cost.');
        }

        return $this->tx->run(function () use ($ctx, $qtyScaled, $unitCostScaled): array {
            $stockLedgerId = $this->stockLedger->createMovement([
                'branch_id' => $ctx['branch_id'],
                'product_id' => $ctx['product_id'],
                'location_id' => $ctx['location_id'],
                'movement_type' => 'purchase',
                'quantity' => $ctx['quantity'],
                'unit_cost' => $ctx['unit_cost'],
                'reference_type' => $ctx['reference_type'],
                'reference_id' => $ctx['reference_id'],
                'correlation_id' => $ctx['correlation_id'],
                'entry_date' => $ctx['entry_date'],
            ], $ctx['organization_id']);

            $inventoryAmountScaled = (int) round(($qtyScaled * $unitCostScaled) / 10000);
            $inventoryAmount = $this->toDecimal($inventoryAmountScaled);

            $journalEntryId = $this->journals->post([
                'organization_id' => $ctx['organization_id'],
                'branch_id' => $ctx['branch_id'],
                'entry_date' => $ctx['entry_date'],
                'reference_type' => 'inventory.purchase',
                'reference_id' => (string) $stockLedgerId,
                'description' => $ctx['description'] ?: 'Inventory purchase receive',
                'created_by' => $ctx['created_by'],
                'source' => 'inventory',
                'correlation_id' => $ctx['correlation_id'],
                'request_id' => $ctx['request_id'],
                'ip' => $ctx['ip'],
                'ip_address' => $ctx['ip_address'],
                'user_agent' => $ctx['user_agent'],
                'lines' => [
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_INVENTORY, $ctx['organization_id']),
                        'debit_amount' => $inventoryAmount,
                        'credit_amount' => '0.0000',
                    ],
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_PURCHASE_CLEARING, $ctx['organization_id'], self::ACC_ACCOUNTS_PAYABLE),
                        'debit_amount' => '0.0000',
                        'credit_amount' => $inventoryAmount,
                    ],
                ],
            ]);

            $this->audit->log([
                'organization_id' => $ctx['organization_id'],
                'branch_id' => $ctx['branch_id'],
                'user_id' => $ctx['created_by'],
                'action_type' => 'inventory.purchase_receive',
                'entity_type' => 'stock_ledger',
                'entity_id' => (string) $stockLedgerId,
                'metadata' => [
                    'product_id' => $ctx['product_id'],
                    'location_id' => $ctx['location_id'],
                    'quantity' => $ctx['quantity'],
                    'unit_cost' => $ctx['unit_cost'],
                ],
                'before_state' => null,
                'after_state' => [
                    'stock_ledger_id' => $stockLedgerId,
                    'journal_entry_id' => $journalEntryId,
                    'movement_type' => 'purchase',
                ],
                'request_id' => $ctx['request_id'],
                'correlation_id' => $ctx['correlation_id'],
                'ip' => $ctx['ip'],
                'ip_address' => $ctx['ip_address'],
                'user_agent' => $ctx['user_agent'],
            ]);

            return [
                'stock_ledger_id' => $stockLedgerId,
                'journal_entry_id' => $journalEntryId,
                'correlation_id' => $ctx['correlation_id'],
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{stock_ledger_id:int,journal_entry_id:int,correlation_id:string,cogs_value:string}
     */
    public function saleIssue(array $payload): array
    {
        $ctx = $this->validateCommonPayload($payload, false);
        $this->assertNotBeforeLatestClosedPeriod($ctx['entry_date'], $ctx['organization_id']);
        $this->periodGuard->assertMutationAllowed($ctx['entry_date'], 'inventory.sale', $ctx['organization_id']);

        $qtyScaled = $this->toScaled($ctx['quantity']);
        if ($qtyScaled <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_sale_quantity',
                'quantity' => $ctx['quantity'],
            ], 'Sale issue requires a positive quantity.');
        }

        $this->costing->ensureLocationStockAvailable(
            $ctx['product_id'],
            $ctx['location_id'],
            $ctx['entry_date'],
            $ctx['quantity'],
            $ctx['organization_id']
        );

        $saleCost = $this->costing->saleCost(
            $ctx['product_id'],
            $ctx['branch_id'],
            $ctx['quantity'],
            $ctx['entry_date'],
            $ctx['organization_id']
        );

        return $this->tx->run(function () use ($ctx, $saleCost): array {
            $stockLedgerId = $this->stockLedger->createMovement([
                'branch_id' => $ctx['branch_id'],
                'product_id' => $ctx['product_id'],
                'location_id' => $ctx['location_id'],
                'movement_type' => 'sale',
                'quantity' => $ctx['quantity'],
                'unit_cost' => $saleCost['unit_cost'],
                'reference_type' => $ctx['reference_type'],
                'reference_id' => $ctx['reference_id'],
                'correlation_id' => $ctx['correlation_id'],
                'entry_date' => $ctx['entry_date'],
            ], $ctx['organization_id']);

            $journalEntryId = $this->journals->post([
                'organization_id' => $ctx['organization_id'],
                'branch_id' => $ctx['branch_id'],
                'entry_date' => $ctx['entry_date'],
                'reference_type' => 'inventory.sale_issue',
                'reference_id' => (string) $stockLedgerId,
                'description' => $ctx['description'] ?: 'Inventory sale issue',
                'created_by' => $ctx['created_by'],
                'source' => 'inventory',
                'correlation_id' => $ctx['correlation_id'],
                'request_id' => $ctx['request_id'],
                'ip' => $ctx['ip'],
                'ip_address' => $ctx['ip_address'],
                'user_agent' => $ctx['user_agent'],
                'lines' => [
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_COGS, $ctx['organization_id']),
                        'debit_amount' => $saleCost['total_value'],
                        'credit_amount' => '0.0000',
                    ],
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_INVENTORY, $ctx['organization_id']),
                        'debit_amount' => '0.0000',
                        'credit_amount' => $saleCost['total_value'],
                    ],
                ],
            ]);

            $this->audit->log([
                'organization_id' => $ctx['organization_id'],
                'branch_id' => $ctx['branch_id'],
                'user_id' => $ctx['created_by'],
                'action_type' => 'inventory.sale_issue',
                'entity_type' => 'stock_ledger',
                'entity_id' => (string) $stockLedgerId,
                'metadata' => [
                    'product_id' => $ctx['product_id'],
                    'location_id' => $ctx['location_id'],
                    'quantity' => $ctx['quantity'],
                    'unit_cost' => $saleCost['unit_cost'],
                ],
                'before_state' => null,
                'after_state' => [
                    'stock_ledger_id' => $stockLedgerId,
                    'journal_entry_id' => $journalEntryId,
                    'movement_type' => 'sale',
                ],
                'request_id' => $ctx['request_id'],
                'correlation_id' => $ctx['correlation_id'],
                'ip' => $ctx['ip'],
                'ip_address' => $ctx['ip_address'],
                'user_agent' => $ctx['user_agent'],
            ]);

            return [
                'stock_ledger_id' => $stockLedgerId,
                'journal_entry_id' => $journalEntryId,
                'correlation_id' => $ctx['correlation_id'],
                'cogs_value' => $saleCost['total_value'],
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{stock_ledger_id:int,journal_entry_id:int,correlation_id:string}
     */
    public function stockAdjustment(array $payload): array
    {
        $ctx = $this->validateCommonPayload($payload, false);
        $this->assertNotBeforeLatestClosedPeriod($ctx['entry_date'], $ctx['organization_id']);
        $this->periodGuard->assertMutationAllowed($ctx['entry_date'], 'inventory.adjustment', $ctx['organization_id']);

        $qtyScaled = $this->toScaled($ctx['quantity']);
        if ($qtyScaled <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_adjustment_quantity',
                'quantity' => $ctx['quantity'],
            ], 'Stock adjustment quantity must be positive.');
        }

        $this->costing->ensureLocationStockAvailable(
            $ctx['product_id'],
            $ctx['location_id'],
            $ctx['entry_date'],
            $ctx['quantity'],
            $ctx['organization_id']
        );

        $saleCost = $this->costing->saleCost(
            $ctx['product_id'],
            $ctx['branch_id'],
            $ctx['quantity'],
            $ctx['entry_date'],
            $ctx['organization_id']
        );

        return $this->tx->run(function () use ($ctx, $saleCost): array {
            $stockLedgerId = $this->stockLedger->createMovement([
                'branch_id' => $ctx['branch_id'],
                'product_id' => $ctx['product_id'],
                'location_id' => $ctx['location_id'],
                'movement_type' => 'adjustment',
                'quantity' => $ctx['quantity'],
                'unit_cost' => $saleCost['unit_cost'],
                'reference_type' => $ctx['reference_type'],
                'reference_id' => $ctx['reference_id'],
                'correlation_id' => $ctx['correlation_id'],
                'entry_date' => $ctx['entry_date'],
            ], $ctx['organization_id']);

            $journalEntryId = $this->journals->post([
                'organization_id' => $ctx['organization_id'],
                'branch_id' => $ctx['branch_id'],
                'entry_date' => $ctx['entry_date'],
                'reference_type' => 'inventory.adjustment',
                'reference_id' => (string) $stockLedgerId,
                'description' => $ctx['description'] ?: 'Inventory stock adjustment',
                'created_by' => $ctx['created_by'],
                'source' => 'inventory',
                'correlation_id' => $ctx['correlation_id'],
                'request_id' => $ctx['request_id'],
                'ip' => $ctx['ip'],
                'ip_address' => $ctx['ip_address'],
                'user_agent' => $ctx['user_agent'],
                'lines' => [
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_STOCK_ADJUSTMENT, $ctx['organization_id']),
                        'debit_amount' => $saleCost['total_value'],
                        'credit_amount' => '0.0000',
                    ],
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_INVENTORY, $ctx['organization_id']),
                        'debit_amount' => '0.0000',
                        'credit_amount' => $saleCost['total_value'],
                    ],
                ],
            ]);

            $this->audit->log([
                'organization_id' => $ctx['organization_id'],
                'branch_id' => $ctx['branch_id'],
                'user_id' => $ctx['created_by'],
                'action_type' => 'inventory.stock_adjustment',
                'entity_type' => 'stock_ledger',
                'entity_id' => (string) $stockLedgerId,
                'metadata' => [
                    'product_id' => $ctx['product_id'],
                    'location_id' => $ctx['location_id'],
                    'quantity' => $ctx['quantity'],
                    'unit_cost' => $saleCost['unit_cost'],
                ],
                'before_state' => null,
                'after_state' => [
                    'stock_ledger_id' => $stockLedgerId,
                    'journal_entry_id' => $journalEntryId,
                    'movement_type' => 'adjustment',
                ],
                'request_id' => $ctx['request_id'],
                'correlation_id' => $ctx['correlation_id'],
                'ip' => $ctx['ip'],
                'ip_address' => $ctx['ip_address'],
                'user_agent' => $ctx['user_agent'],
            ]);

            return [
                'stock_ledger_id' => $stockLedgerId,
                'journal_entry_id' => $journalEntryId,
                'correlation_id' => $ctx['correlation_id'],
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{transfer_out_stock_ledger_id:int,transfer_in_stock_ledger_id:int,journal_entry_id:int,correlation_id:string}
     */
    public function transfer(array $payload): array
    {
        $organizationId = isset($payload['organization_id']) ? (int) $payload['organization_id'] : null;
        $sourceBranchId = (int) ($payload['source_branch_id'] ?? 0);
        $sourceLocationId = (int) ($payload['source_location_id'] ?? 0);
        $destinationBranchId = (int) ($payload['destination_branch_id'] ?? 0);
        $destinationLocationId = (int) ($payload['destination_location_id'] ?? 0);
        $productId = (int) ($payload['product_id'] ?? 0);
        $quantity = (string) ($payload['quantity'] ?? '0');
        $entryDate = (string) ($payload['entry_date'] ?? '');
        $createdBy = (int) ($payload['created_by'] ?? 0);
        $referenceType = trim((string) ($payload['reference_type'] ?? 'inventory.transfer'));
        $referenceId = isset($payload['reference_id']) ? (string) $payload['reference_id'] : null;
        $description = trim((string) ($payload['description'] ?? ''));
        $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : null;
        $correlationId = trim((string) ($payload['correlation_id'] ?? ''));
        if ($correlationId === '') {
            $correlationId = $this->uuidV4();
        }

        if ($sourceBranchId <= 0 || $destinationBranchId <= 0 || $sourceLocationId <= 0 || $destinationLocationId <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_transfer_location_or_branch',
            ], 'Transfer requires valid source and destination branches/locations.');
        }

        if ($productId <= 0 || $createdBy <= 0 || $entryDate === '') {
            throw new InventoryValidationException([
                'code' => 'invalid_transfer_payload',
            ], 'Transfer payload is incomplete.');
        }

        $qtyScaled = $this->toScaled($quantity);
        if ($qtyScaled <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_transfer_quantity',
                'quantity' => $quantity,
            ], 'Transfer quantity must be positive.');
        }

        if (!$this->branches->isActiveInOrganization($sourceBranchId, $organizationId)) {
            throw new InventoryValidationException([
                'code' => 'source_branch_invalid',
                'source_branch_id' => $sourceBranchId,
            ], 'Source branch is invalid for transfer.');
        }

        if (!$this->branches->isActiveInOrganization($destinationBranchId, $organizationId)) {
            throw new InventoryValidationException([
                'code' => 'destination_branch_invalid',
                'destination_branch_id' => $destinationBranchId,
            ], 'Destination branch is invalid for transfer.');
        }

        $product = $this->products->findById($productId, $organizationId);
        if ($product === null || (int) ($product['is_active'] ?? 0) !== 1) {
            throw new InventoryValidationException([
                'code' => 'product_not_active',
                'product_id' => $productId,
            ], 'Product is not active.');
        }

        if (($product['type'] ?? '') !== 'inventory') {
            throw new InventoryValidationException([
                'code' => 'service_product_stock_blocked',
                'product_id' => $productId,
            ], 'Service type products cannot affect stock.');
        }

        $sourceLocation = $this->locations->findById($sourceLocationId, $organizationId);
        $destinationLocation = $this->locations->findById($destinationLocationId, $organizationId);
        if ($sourceLocation === null || $destinationLocation === null) {
            throw new InventoryValidationException([
                'code' => 'location_not_found',
                'source_location_id' => $sourceLocationId,
                'destination_location_id' => $destinationLocationId,
            ], 'Source or destination location does not exist.');
        }

        if ((int) $sourceLocation['branch_id'] !== $sourceBranchId || (int) $destinationLocation['branch_id'] !== $destinationBranchId) {
            throw new InventoryValidationException([
                'code' => 'location_branch_mismatch',
                'source_branch_id' => $sourceBranchId,
                'source_location_branch_id' => (int) $sourceLocation['branch_id'],
                'destination_branch_id' => $destinationBranchId,
                'destination_location_branch_id' => (int) $destinationLocation['branch_id'],
            ], 'Transfer location does not match branch.');
        }

        if ((string) ($destinationLocation['type'] ?? '') !== 'transit') {
            throw new InventoryValidationException([
                'code' => 'destination_location_not_transit',
                'destination_location_id' => $destinationLocationId,
                'location_type' => $destinationLocation['type'] ?? null,
            ], 'Transfer issue destination must be a transit location.');
        }

        $this->assertNotBeforeLatestClosedPeriod($entryDate, $organizationId);
        $this->periodGuard->assertMutationAllowed($entryDate, 'inventory.transfer', $organizationId);
        $this->costing->ensureLocationStockAvailable($productId, $sourceLocationId, $entryDate, $quantity, $organizationId);
        $sourceCost = $this->costing->saleCost($productId, $sourceBranchId, $quantity, $entryDate, $organizationId);

        return $this->tx->run(function () use (
            $organizationId,
            $sourceBranchId,
            $sourceLocationId,
            $destinationBranchId,
            $destinationLocationId,
            $productId,
            $quantity,
            $entryDate,
            $createdBy,
            $referenceType,
            $referenceId,
            $description,
            $requestId,
            $correlationId,
            $sourceCost
        ): array {
            $transferOutId = $this->stockLedger->createMovement([
                'branch_id' => $sourceBranchId,
                'product_id' => $productId,
                'location_id' => $sourceLocationId,
                'movement_type' => 'transfer_out',
                'quantity' => $quantity,
                'unit_cost' => $sourceCost['unit_cost'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'correlation_id' => $correlationId,
                'entry_date' => $entryDate,
            ], $organizationId);

            $transferInId = $this->stockLedger->createMovement([
                'branch_id' => $destinationBranchId,
                'product_id' => $productId,
                'location_id' => $destinationLocationId,
                'movement_type' => 'transfer_in',
                'quantity' => $quantity,
                'unit_cost' => $sourceCost['unit_cost'],
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'correlation_id' => $correlationId,
                'entry_date' => $entryDate,
            ], $organizationId);

            $journalEntryId = $this->journals->post([
                'organization_id' => $organizationId,
                'branch_id' => $sourceBranchId,
                'entry_date' => $entryDate,
                'reference_type' => 'inventory.transfer',
                'reference_id' => (string) $transferOutId,
                'description' => $description ?: 'Inventory transfer out to transit',
                'created_by' => $createdBy,
                'source' => 'inventory',
                'correlation_id' => $correlationId,
                'request_id' => $requestId,
                'lines' => [
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_STOCK_IN_TRANSIT, $organizationId),
                        'debit_amount' => $sourceCost['total_value'],
                        'credit_amount' => '0.0000',
                    ],
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_INVENTORY, $organizationId),
                        'debit_amount' => '0.0000',
                        'credit_amount' => $sourceCost['total_value'],
                    ],
                ],
            ]);

            $this->audit->log([
                'organization_id' => $organizationId,
                'branch_id' => $sourceBranchId,
                'user_id' => $createdBy,
                'action_type' => 'inventory.transfer',
                'entity_type' => 'stock_ledger',
                'entity_id' => (string) $transferOutId,
                'metadata' => [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'source_branch_id' => $sourceBranchId,
                    'destination_branch_id' => $destinationBranchId,
                ],
                'before_state' => null,
                'after_state' => [
                    'transfer_out_stock_ledger_id' => $transferOutId,
                    'transfer_in_stock_ledger_id' => $transferInId,
                    'journal_entry_id' => $journalEntryId,
                ],
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'ip' => null,
                'ip_address' => null,
                'user_agent' => 'inventory_transfer',
            ]);

            return [
                'transfer_out_stock_ledger_id' => $transferOutId,
                'transfer_in_stock_ledger_id' => $transferInId,
                'journal_entry_id' => $journalEntryId,
                'correlation_id' => $correlationId,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{transit_out_stock_ledger_id:int,destination_in_stock_ledger_id:int,journal_entry_id:int,correlation_id:string}
     */
    public function transferReceive(array $payload): array
    {
        $organizationId = isset($payload['organization_id']) ? (int) $payload['organization_id'] : null;
        $entryDate = (string) ($payload['entry_date'] ?? '');
        $createdBy = (int) ($payload['created_by'] ?? 0);
        $destinationBranchId = (int) ($payload['destination_branch_id'] ?? 0);
        $destinationLocationId = (int) ($payload['destination_location_id'] ?? 0);
        $transferOutStockLedgerId = (int) ($payload['transfer_out_stock_ledger_id'] ?? 0);
        $transferInStockLedgerId = (int) ($payload['transfer_in_stock_ledger_id'] ?? 0);
        $referenceType = trim((string) ($payload['reference_type'] ?? 'inventory.transfer.receive'));
        $referenceId = isset($payload['reference_id']) ? (string) $payload['reference_id'] : null;
        $description = trim((string) ($payload['description'] ?? ''));
        $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : null;
        $correlationId = trim((string) ($payload['correlation_id'] ?? ''));

        if (
            $organizationId === null
            || $entryDate === ''
            || $createdBy <= 0
            || $destinationBranchId <= 0
            || $destinationLocationId <= 0
            || $transferOutStockLedgerId <= 0
            || $transferInStockLedgerId <= 0
        ) {
            throw new InventoryValidationException([
                'code' => 'invalid_transfer_receive_payload',
            ], 'Transfer receive payload is incomplete.');
        }

        $transferOutLeg = $this->stockLedger->findById($transferOutStockLedgerId, $organizationId);
        $transferInTransitLeg = $this->stockLedger->findById($transferInStockLedgerId, $organizationId);
        if ($transferOutLeg === null || $transferInTransitLeg === null) {
            throw new InventoryValidationException([
                'code' => 'transfer_legs_not_found',
                'transfer_out_stock_ledger_id' => $transferOutStockLedgerId,
                'transfer_in_stock_ledger_id' => $transferInStockLedgerId,
            ], 'Transfer receive requires existing transfer out and transfer in legs.');
        }

        if (
            (string) $transferOutLeg['movement_type'] !== 'transfer_out'
            || (string) $transferInTransitLeg['movement_type'] !== 'transfer_in'
        ) {
            throw new InventoryValidationException([
                'code' => 'transfer_legs_invalid',
                'transfer_out_movement_type' => $transferOutLeg['movement_type'],
                'transfer_in_movement_type' => $transferInTransitLeg['movement_type'],
            ], 'Transfer receive requires transfer_out and transfer_in source legs.');
        }

        if ((int) $transferOutLeg['product_id'] !== (int) $transferInTransitLeg['product_id']) {
            throw new InventoryValidationException([
                'code' => 'transfer_product_mismatch',
                'transfer_out_product_id' => (int) $transferOutLeg['product_id'],
                'transfer_in_product_id' => (int) $transferInTransitLeg['product_id'],
            ], 'Transfer legs do not share the same product.');
        }

        if ($this->toScaled((string) $transferOutLeg['quantity']) !== $this->toScaled((string) $transferInTransitLeg['quantity'])) {
            throw new InventoryValidationException([
                'code' => 'transfer_quantity_mismatch',
                'transfer_out_quantity' => (string) $transferOutLeg['quantity'],
                'transfer_in_quantity' => (string) $transferInTransitLeg['quantity'],
            ], 'Transfer legs do not share the same quantity.');
        }

        if ($this->toScaled((string) $transferOutLeg['unit_cost']) !== $this->toScaled((string) $transferInTransitLeg['unit_cost'])) {
            throw new InventoryValidationException([
                'code' => 'transfer_unit_cost_mismatch',
                'transfer_out_unit_cost' => (string) $transferOutLeg['unit_cost'],
                'transfer_in_unit_cost' => (string) $transferInTransitLeg['unit_cost'],
            ], 'Transfer legs do not share the same unit cost.');
        }

        if (
            (string) $transferOutLeg['correlation_id'] === ''
            || (string) $transferOutLeg['correlation_id'] !== (string) $transferInTransitLeg['correlation_id']
        ) {
            throw new InventoryValidationException([
                'code' => 'transfer_correlation_mismatch',
                'transfer_out_correlation_id' => (string) $transferOutLeg['correlation_id'],
                'transfer_in_correlation_id' => (string) $transferInTransitLeg['correlation_id'],
            ], 'Transfer legs are not correlated to the same transfer transaction.');
        }

        $transitLocation = $this->locations->findById((int) $transferInTransitLeg['location_id'], $organizationId);
        if ($transitLocation === null || (string) ($transitLocation['type'] ?? '') !== 'transit') {
            throw new InventoryValidationException([
                'code' => 'transfer_source_not_transit',
                'location_id' => (int) $transferInTransitLeg['location_id'],
                'location_type' => $transitLocation['type'] ?? null,
            ], 'Transfer receive must source from a transit location.');
        }

        if (!$this->branches->isActiveInOrganization($destinationBranchId, $organizationId)) {
            throw new InventoryValidationException([
                'code' => 'destination_branch_invalid',
                'destination_branch_id' => $destinationBranchId,
            ], 'Destination branch is invalid for transfer receive.');
        }

        $destinationLocation = $this->locations->findById($destinationLocationId, $organizationId);
        if ($destinationLocation === null) {
            throw new InventoryValidationException([
                'code' => 'destination_location_not_found',
                'destination_location_id' => $destinationLocationId,
            ], 'Destination location does not exist.');
        }

        if ((int) $destinationLocation['branch_id'] !== $destinationBranchId) {
            throw new InventoryValidationException([
                'code' => 'destination_location_branch_mismatch',
                'destination_branch_id' => $destinationBranchId,
                'destination_location_branch_id' => (int) $destinationLocation['branch_id'],
            ], 'Destination location does not match destination branch.');
        }

        if ((string) ($destinationLocation['type'] ?? '') === 'transit') {
            throw new InventoryValidationException([
                'code' => 'destination_location_cannot_be_transit',
                'destination_location_id' => $destinationLocationId,
            ], 'Transfer receive destination must be a non-transit location.');
        }

        $completionRefId = $referenceId ?: (string) $transferInStockLedgerId;
        if ($this->stockLedger->countByReference($referenceType, $completionRefId, $organizationId) > 0) {
            throw new InventoryValidationException([
                'code' => 'transfer_receive_already_posted',
                'reference_id' => $completionRefId,
            ], 'Transfer receive has already been posted for this transfer leg.');
        }

        if ($correlationId === '') {
            $correlationId = (string) $transferOutLeg['correlation_id'];
        }

        $quantity = $this->toDecimal($this->toScaled((string) $transferInTransitLeg['quantity']));
        $unitCost = $this->toDecimal($this->toScaled((string) $transferInTransitLeg['unit_cost']));
        $productId = (int) $transferInTransitLeg['product_id'];
        $transitBranchId = (int) $transferInTransitLeg['branch_id'];
        $transitLocationId = (int) $transferInTransitLeg['location_id'];

        $this->assertNotBeforeLatestClosedPeriod($entryDate, $organizationId);
        $this->periodGuard->assertMutationAllowed($entryDate, 'inventory.transfer_receive', $organizationId);
        $this->costing->ensureLocationStockAvailable($productId, $transitLocationId, $entryDate, $quantity, $organizationId);

        return $this->tx->run(function () use (
            $organizationId,
            $entryDate,
            $createdBy,
            $destinationBranchId,
            $destinationLocationId,
            $referenceType,
            $completionRefId,
            $description,
            $requestId,
            $correlationId,
            $quantity,
            $unitCost,
            $productId,
            $transitBranchId,
            $transitLocationId,
            $transferOutStockLedgerId,
            $transferInStockLedgerId
        ): array {
            $transitOutStockLedgerId = $this->stockLedger->createMovement([
                'branch_id' => $transitBranchId,
                'product_id' => $productId,
                'location_id' => $transitLocationId,
                'movement_type' => 'transfer_out',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $completionRefId,
                'correlation_id' => $correlationId,
                'entry_date' => $entryDate,
            ], $organizationId);

            $destinationInStockLedgerId = $this->stockLedger->createMovement([
                'branch_id' => $destinationBranchId,
                'product_id' => $productId,
                'location_id' => $destinationLocationId,
                'movement_type' => 'transfer_in',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType,
                'reference_id' => $completionRefId,
                'correlation_id' => $correlationId,
                'entry_date' => $entryDate,
            ], $organizationId);

            $quantityScaled = $this->toScaled($quantity);
            $unitCostScaled = $this->toScaled($unitCost);
            $inventoryAmount = $this->toDecimal((int) round(($quantityScaled * $unitCostScaled) / 10000));

            $journalEntryId = $this->journals->post([
                'organization_id' => $organizationId,
                'branch_id' => $destinationBranchId,
                'entry_date' => $entryDate,
                'reference_type' => 'inventory.transfer_receive',
                'reference_id' => (string) $destinationInStockLedgerId,
                'description' => $description !== '' ? $description : 'Inventory transfer received from transit',
                'created_by' => $createdBy,
                'source' => 'inventory',
                'correlation_id' => $correlationId,
                'request_id' => $requestId,
                'lines' => [
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_INVENTORY, $organizationId),
                        'debit_amount' => $inventoryAmount,
                        'credit_amount' => '0.0000',
                    ],
                    [
                        'account_id' => $this->resolveAccountId(self::ACC_STOCK_IN_TRANSIT, $organizationId),
                        'debit_amount' => '0.0000',
                        'credit_amount' => $inventoryAmount,
                    ],
                ],
            ]);

            $this->audit->log([
                'organization_id' => $organizationId,
                'branch_id' => $destinationBranchId,
                'user_id' => $createdBy,
                'action_type' => 'inventory.transfer_receive',
                'entity_type' => 'stock_ledger',
                'entity_id' => (string) $destinationInStockLedgerId,
                'metadata' => [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'transfer_out_stock_ledger_id' => $transferOutStockLedgerId,
                    'transfer_in_stock_ledger_id' => $transferInStockLedgerId,
                ],
                'before_state' => null,
                'after_state' => [
                    'transit_out_stock_ledger_id' => $transitOutStockLedgerId,
                    'destination_in_stock_ledger_id' => $destinationInStockLedgerId,
                    'journal_entry_id' => $journalEntryId,
                ],
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'ip' => null,
                'ip_address' => null,
                'user_agent' => 'inventory_transfer_receive',
            ]);

            return [
                'transit_out_stock_ledger_id' => $transitOutStockLedgerId,
                'destination_in_stock_ledger_id' => $destinationInStockLedgerId,
                'journal_entry_id' => $journalEntryId,
                'correlation_id' => $correlationId,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validateCommonPayload(array $payload, bool $requireUnitCost): array
    {
        $organizationId = isset($payload['organization_id']) ? (int) $payload['organization_id'] : null;
        $branchId = (int) ($payload['branch_id'] ?? 0);
        $locationId = (int) ($payload['location_id'] ?? 0);
        $productId = (int) ($payload['product_id'] ?? 0);
        $quantity = (string) ($payload['quantity'] ?? '0');
        $unitCost = (string) ($payload['unit_cost'] ?? '0');
        $entryDate = (string) ($payload['entry_date'] ?? '');
        $createdBy = (int) ($payload['created_by'] ?? 0);
        $referenceType = trim((string) ($payload['reference_type'] ?? 'inventory.manual'));
        $referenceId = isset($payload['reference_id']) ? (string) $payload['reference_id'] : null;
        $description = trim((string) ($payload['description'] ?? ''));
        $requestId = isset($payload['request_id']) ? (string) $payload['request_id'] : null;
        $correlationId = trim((string) ($payload['correlation_id'] ?? ''));
        if ($correlationId === '') {
            $correlationId = $this->uuidV4();
        }

        if ($productId <= 0 || $branchId <= 0 || $locationId <= 0 || $createdBy <= 0 || $entryDate === '') {
            throw new InventoryValidationException([
                'code' => 'invalid_inventory_payload',
            ], 'Inventory payload is incomplete.');
        }

        if (!$this->branches->isActiveInOrganization($branchId, $organizationId)) {
            throw new InventoryValidationException([
                'code' => 'invalid_branch_scope',
                'branch_id' => $branchId,
            ], 'Branch is invalid for inventory movement.');
        }

        $product = $this->products->findById($productId, $organizationId);
        if ($product === null || (int) ($product['is_active'] ?? 0) !== 1) {
            throw new InventoryValidationException([
                'code' => 'product_not_active',
                'product_id' => $productId,
            ], 'Product is not active.');
        }

        if (($product['type'] ?? '') !== 'inventory') {
            throw new InventoryValidationException([
                'code' => 'service_product_stock_blocked',
                'product_id' => $productId,
            ], 'Service type products cannot affect stock.');
        }

        $location = $this->locations->findById($locationId, $organizationId);
        if ($location === null) {
            throw new InventoryValidationException([
                'code' => 'location_not_found',
                'location_id' => $locationId,
            ], 'Inventory location does not exist.');
        }

        if ((int) $location['branch_id'] !== $branchId) {
            throw new InventoryValidationException([
                'code' => 'location_branch_mismatch',
                'branch_id' => $branchId,
                'location_branch_id' => (int) $location['branch_id'],
            ], 'Location branch does not match movement branch.');
        }

        $quantityScaled = $this->toScaled($quantity);
        if ($quantityScaled <= 0) {
            throw new InventoryValidationException([
                'code' => 'invalid_quantity',
                'quantity' => $quantity,
            ], 'Movement quantity must be positive.');
        }

        if ($requireUnitCost) {
            $unitCostScaled = $this->toScaled($unitCost);
            if ($unitCostScaled <= 0) {
                throw new InventoryValidationException([
                    'code' => 'invalid_unit_cost',
                    'unit_cost' => $unitCost,
                ], 'Unit cost must be positive.');
            }
        }

        return [
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'quantity' => $this->toDecimal($quantityScaled),
            'unit_cost' => $this->toDecimal($this->toScaled($unitCost)),
            'entry_date' => $entryDate,
            'created_by' => $createdBy,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'ip' => $payload['ip'] ?? null,
            'ip_address' => $payload['ip_address'] ?? null,
            'user_agent' => $payload['user_agent'] ?? null,
        ];
    }

    private function assertNotBeforeLatestClosedPeriod(string $entryDate, ?int $organizationId = null): void
    {
        $normalizedEntryDate = (new \DateTimeImmutable($entryDate))->format('Y-m-d');
        $latestClosed = $this->periods->findLatestClosedPeriod($organizationId);
        if ($latestClosed === null) {
            return;
        }

        $latestClosedEnd = (new \DateTimeImmutable((string) $latestClosed['period_end']))->format('Y-m-d');
        if ($normalizedEntryDate > $latestClosedEnd) {
            return;
        }

        $requiredMinDate = (new \DateTimeImmutable($latestClosedEnd))->modify('+1 day')->format('Y-m-d');
        throw new InventoryValidationException([
            'code' => 'entry_date_before_latest_closed_period',
            'entry_date' => $normalizedEntryDate,
            'latest_closed_period_id' => (int) $latestClosed['id'],
            'latest_closed_period_end' => $latestClosedEnd,
            'required_min_date' => $requiredMinDate,
        ], 'Inventory movement date must be after the latest closed accounting period end date.');
    }

    private function resolveAccountId(string $code, ?int $organizationId = null, ?string $fallbackCode = null): int
    {
        $cacheKey = $code . '|' . ($organizationId ?? 0) . '|' . ($fallbackCode ?? '');
        if (isset($this->accountIdCache[$cacheKey])) {
            return $this->accountIdCache[$cacheKey];
        }

        $account = $this->accounts->findByCode($code, $organizationId);
        if ($account === null && $fallbackCode !== null) {
            $account = $this->accounts->findByCode($fallbackCode, $organizationId);
        }

        if ($account === null || (int) ($account['is_active'] ?? 0) !== 1) {
            throw new InvariantViolationException([
                'code' => 'inventory_account_mapping_missing',
                'requested_code' => $code,
                'fallback_code' => $fallbackCode,
            ], 'Required inventory account mapping is missing or inactive.');
        }

        $accountId = (int) $account['id'];
        $this->accountIdCache[$cacheKey] = $accountId;
        return $accountId;
    }

    private function toScaled(string $amount): int
    {
        return (int) round(((float) $amount) * 10000);
    }

    private function toDecimal(int $amount): string
    {
        return number_format($amount / 10000, 4, '.', '');
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
