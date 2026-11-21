<?php

namespace Webkul\Branch\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webkul\Branch\Enums\TransferType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Models\BranchTransferSaleLine;
use Webkul\Inventory\Enums\MoveState;
use Webkul\Inventory\Enums\OperationState;
use Webkul\Inventory\Enums\OperationType as OperationTypeEnum;
use Webkul\Inventory\Facades\Inventory as InventoryFacade;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Move;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\OperationType;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Inventory\Services\MoveReserver;
use Webkul\Inventory\Services\TransferWorkflow;
use Webkul\Product\Enums\ProductType;

/**
 * The bridge between a transfer document and the inventory engine
 * (plan §7.2, §9 Phase 3).
 *
 * Two operations carry the goods, so ship and receive stay separate
 * stock events:
 *
 * - Outbound (created at confirm, completed at ship): source
 *   warehouse Stock → Inter Warehouse Transit. Completing it takes
 *   stock out of the source branch; the goods then sit in transit.
 * - Receipt (created and completed at receive): Inter Warehouse
 *   Transit → destination warehouse Stock. One receipt operation
 *   per receive call, so partial receipts work naturally.
 *
 * Service-type products hold no stock, so transfers with only
 * service lines move no stock at all — the document still flows
 * through confirm / ship / receive as a status record.
 *
 * The engine is reused, never rewritten: Operations and Moves are
 * driven through the Inventory facade (confirm / reserve /
 * complete) exactly as the built-in internal transfer does.
 */
class TransferStockService
{
    public function __construct(
        protected StockLayerService $layers,
    ) {}

    /** Whether this transfer moves physical stock at all. */
    public function movesStock(BranchTransferSale $transfer): bool
    {
        $transfer->loadMissing('lines.product');

        return $transfer->lines->contains(
            fn (BranchTransferSaleLine $line) => $line->product && $line->product->type !== ProductType::SERVICE
        );
    }

    /** Storable (non-service) lines of a transfer. */
    public function stockLines(BranchTransferSale $transfer): array
    {
        $transfer->loadMissing('lines.product');

        return $transfer->lines
            ->filter(fn (BranchTransferSaleLine $line) => $line->product && $line->product->type !== ProductType::SERVICE)
            ->values()
            ->all();
    }

    /**
     * The seeded "Inter Warehouse Transit" location (plan §5.2a:
     * goods on the road between ship and receipt). It is soft-deleted
     * on one-step warehouses, so it is restored on first use. If it
     * was never seeded, it is created under Physical Locations.
     */
    public function transitLocation(): Location
    {
        $location = Location::withTrashed()
            ->where('name', 'Inter Warehouse Transit')
            ->where('type', 'transit')
            ->first();

        if ($location) {
            if ($location->trashed()) {
                $location->restore();
            }

            return $location;
        }

        $parent = Location::query()
            ->where('name', 'Physical Locations')
            ->where('type', 'view')
            ->first();

        return Location::create([
            'name'      => 'Inter Warehouse Transit',
            'type'      => 'transit',
            'parent_id' => $parent?->getKey(),
        ]);
    }

    /**
     * The warehouse's "Internal Transfers" operation type. One-step
     * warehouses archive it at install, so it is restored on first
     * use (the same fix Phase 0 applied to the seeded warehouse).
     */
    public function internalOperationType(Warehouse $warehouse): OperationType
    {
        $type = OperationType::withTrashed()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('type', OperationTypeEnum::INTERNAL)
            ->where('name', 'Internal Transfers')
            ->first()
            ?? OperationType::withTrashed()
                ->where('warehouse_id', $warehouse->getKey())
                ->where('type', OperationTypeEnum::INTERNAL)
                ->first();

        if (! $type) {
            throw ValidationException::withMessages([
                'source_branch_id' => "Warehouse {$warehouse->name} has no internal transfer operation type.",
            ]);
        }

        if ($type->trashed()) {
            $type->restore();
        }

        return $type;
    }

    public function stockLocation(Branch $branch): Location
    {
        $warehouse = $branch->warehouse ?? throw ValidationException::withMessages([
            'source_branch_id' => "Branch {$branch->name} has no warehouse.",
        ]);

        return $warehouse->lotStockLocation
            ?? throw ValidationException::withMessages([
                'source_branch_id' => "Warehouse {$warehouse->name} has no stock location.",
            ]);
    }

    /**
     * Create the outbound Operation + Moves for a transfer and
     * reserve the stock at the source branch (plan §9 Phase 3.1).
     * Runs inside the caller's transaction (BranchTransferService).
     */
    public function createOutboundOperation(BranchTransferSale $transfer): Operation
    {
        $source = Branch::withoutGlobalScopes()->findOrFail($transfer->source_branch_id);
        $destination = Branch::withoutGlobalScopes()->findOrFail($transfer->destination_branch_id);
        $sourceWarehouse = Warehouse::query()->findOrFail($transfer->source_warehouse_id);

        $lines = $this->stockLines($transfer);

        $operation = $this->createOperation(
            $transfer,
            $this->internalOperationType($sourceWarehouse),
            $this->stockLocation($source),
            $this->transitLocation(),
            $source,
            $destination,
        );

        foreach ($lines as $line) {
            $move = $this->createMove($operation, $line, (float) $line->quantity, $source, $transfer);

            $line->forceFill(['inventory_move_id' => $move->getKey()])->save();
        }

        $transfer->forceFill(['operation_id' => $operation->getKey()])->save();

        InventoryFacade::confirmTransfer($operation);
        InventoryFacade::reserveTransfer($operation);

        $operation->refresh();
        $operation->load('moves');

        $short = $operation->moves->filter(
            fn (Move $move) => $move->state !== MoveState::ASSIGNED
        );

        if ($short->isNotEmpty()) {
            app(TransferWorkflow::class)->cancel($operation);

            throw ValidationException::withMessages([
                'lines' => 'Could not reserve the full quantity at the source branch.',
            ]);
        }

        return $operation->refresh();
    }

    /**
     * Ship the outbound operation (plan §9 Phase 3.2): consume the
     * FIFO layers, capture the cost per line, and complete the
     * Moves so the stock leaves the source branch into transit.
     *
     * @param  array<int, float>  $shippedQuantities  line id => shipped qty
     */
    public function shipOutboundOperation(BranchTransferSale $transfer, array $shippedQuantities): Operation
    {
        $operation = Operation::query()->findOrFail($transfer->operation_id);
        $operation->refresh();
        $operation->load('moves');

        $source = Branch::withoutGlobalScopes()->findOrFail($transfer->source_branch_id);
        $sourceLocation = $this->stockLocation($source);

        $transfer->loadMissing('lines.product');

        foreach ($transfer->lines as $line) {
            if (! $line->product || $line->product->type === ProductType::SERVICE) {
                continue;
            }

            $shipped = round((float) ($shippedQuantities[$line->getKey()] ?? $line->quantity), 4);

            if ($shipped <= 0) {
                continue;
            }

            $move = $operation->moves->firstWhere('product_id', $line->product_id);

            if ($move && $shipped < (float) $move->product_uom_qty - 0.0001) {
                $this->resizeMoveForPartialShip($move, $shipped);
            }

            $unitCost = $this->layers->consumeFifo($source, $line, $shipped, $sourceLocation->getKey());

            $line->forceFill([
                'cost_price_unit' => $unitCost,
                'cost_subtotal'   => round($shipped * $unitCost, 4),
            ])->save();
        }

        // The resize above loads `lines` onto the move models; the
        // engine's completer deletes unpicked lines of a picked move
        // and then refreshes every line it still holds, so a stale
        // unpicked copy must not survive into complete(). Drop the
        // relation so the engine loads moves (and their lines) fresh,
        // after it has marked everything picked.
        $operation->unsetRelation('moves');
        $operation->refresh();

        InventoryFacade::completeTransfer($operation);

        $operation->refresh();

        if ($operation->state !== OperationState::DONE) {
            throw ValidationException::withMessages([
                'status' => "The outbound stock operation {$operation->name} did not complete (state: {$operation->state->value}).",
            ]);
        }

        $transfer->recomputeTotals();

        return $operation;
    }

    /**
     * Create and immediately complete the receipt Operation for one
     * receive call (plan §9 Phase 3.3): Transit → destination Stock.
     * Creates the destination branch's stock layers at P. Returns
     * null when nothing was received in this call (no operation).
     *
     * @param  array<int, float>  $receivedQuantities  line id => received qty this call
     */
    public function createReceiptOperation(BranchTransferSale $transfer, array $receivedQuantities): ?Operation
    {
        $transfer->loadMissing('lines.product');

        if (! collect($receivedQuantities)->contains(fn ($qty) => (float) $qty > 0)) {
            return null;
        }

        $source = Branch::withoutGlobalScopes()->findOrFail($transfer->source_branch_id);
        $destination = Branch::withoutGlobalScopes()->findOrFail($transfer->destination_branch_id);
        $destinationWarehouse = Warehouse::query()->findOrFail($transfer->destination_warehouse_id);
        $destinationLocation = $this->stockLocation($destination);

        $operation = $this->createOperation(
            $transfer,
            $this->internalOperationType($destinationWarehouse),
            $this->transitLocation(),
            $destinationLocation,
            $source,
            $destination,
        );

        foreach ($transfer->lines as $line) {
            if (! $line->product || $line->product->type === ProductType::SERVICE) {
                continue;
            }

            $received = round((float) ($receivedQuantities[$line->getKey()] ?? 0), 4);

            if ($received <= 0) {
                continue;
            }

            $this->createMove($operation, $line, $received, $destination, $transfer);
        }

        InventoryFacade::confirmTransfer($operation);
        InventoryFacade::reserveTransfer($operation);
        InventoryFacade::completeTransfer($operation);

        $operation->refresh();

        if ($operation->state !== OperationState::DONE) {
            throw ValidationException::withMessages([
                'status' => "The receipt stock operation {$operation->name} did not complete (state: {$operation->state->value}).",
            ]);
        }

        $transfer->forceFill(['receipt_operation_id' => $operation->getKey()])->save();

        foreach ($transfer->lines as $line) {
            if (! $line->product || $line->product->type === ProductType::SERVICE) {
                continue;
            }

            $received = round((float) ($receivedQuantities[$line->getKey()] ?? 0), 4);

            if ($received > 0) {
                // A return receipt goes back to Main at the original
                // FIFO cost C, not at the transfer price P — the
                // ledger posts Dr 110100 C, so the layer must match
                // (Phase 4). The original line's stored cost is the C.
                $unitCostOverride = null;

                if ($transfer->type === TransferType::Return && $transfer->parent_transfer_id) {
                    $originalLine = BranchTransferSaleLine::query()
                        ->where('transfer_id', $transfer->parent_transfer_id)
                        ->where('product_id', $line->product_id)
                        ->first();

                    if ($originalLine && $originalLine->cost_price_unit !== null) {
                        $unitCostOverride = (float) $originalLine->cost_price_unit;
                    }
                }

                $this->layers->createReceiptLayer($line, $received, $destination, $destinationLocation->getKey(), $unitCostOverride);
            }
        }

        return $operation;
    }

    /**
     * Return in-transit stock to the source branch (cancel after
     * ship, Phase 4): build the engine return of the outbound
     * operation (Transit → source Stock), complete it, and restore
     * the FIFO layers the ship consumed.
     */
    public function returnTransitToSource(BranchTransferSale $transfer): ?Operation
    {
        $outbound = Operation::query()->find($transfer->operation_id);

        if (! $outbound) {
            return null;
        }

        $outbound->loadMissing('moves');

        $moveQuantities = [];

        foreach ($this->stockLines($transfer) as $line) {
            $move = $outbound->moves->firstWhere('product_id', $line->product_id);

            if (! $move) {
                continue;
            }

            $moveQuantities[$move->getKey()] = (float) $line->quantity_shipped;
        }

        if ($moveQuantities === []) {
            return null;
        }

        $returnOperation = app(TransferWorkflow::class)
            ->createReturn($outbound, $moveQuantities);

        $returnOperation->forceFill([
            'branch_transfer_sale_id' => $transfer->getKey(),
            'source_branch_id'        => $transfer->destination_branch_id,
            'destination_branch_id'   => $transfer->source_branch_id,
        ])->save();

        foreach ($returnOperation->moves as $move) {
            $move->forceFill([
                'branch_transfer_sale_id' => $transfer->getKey(),
                'branch_id'               => $transfer->destination_branch_id,
            ])->save();
        }

        $returnOperation->refresh();

        InventoryFacade::completeTransfer($returnOperation);

        $returnOperation->refresh();

        if ($returnOperation->state !== OperationState::DONE) {
            throw ValidationException::withMessages([
                'status' => "The stock return operation {$returnOperation->name} did not complete (state: {$returnOperation->state->value}).",
            ]);
        }

        foreach ($this->stockLines($transfer) as $line) {
            $this->layers->restoreConsumptions($line);
        }

        return $returnOperation;
    }

    /** Release the reservations of a not-yet-shipped operation. */
    public function cancelOperation(Operation $operation): void
    {
        $operation->refresh();

        if (in_array($operation->state, [OperationState::DONE, OperationState::CANCELED], true)) {
            return;
        }

        app(TransferWorkflow::class)->cancel($operation);
    }

    /**
     * Build the return transfer's outbound operation from the
     * original transfer's receipt operation, reusing the engine's
     * own return mechanics (TransferWorkflow::createReturn, which
     * uses buildReturnOperationAttributes). Returns null when the
     * original receipt operation cannot cover the return — the
     * caller then falls back to a fresh outbound operation.
     */
    public function createReturnOperation(BranchTransferSale $returnTransfer, BranchTransferSale $original): ?Operation
    {
        $receiptOperation = $original->receiptOperation()->first()
            ?? Operation::query()
                ->where('branch_transfer_sale_id', $original->getKey())
                ->where('id', '!=', $original->operation_id)
                ->orderByDesc('id')
                ->first();

        if (! $receiptOperation) {
            return null;
        }

        $receiptOperation->loadMissing('moves');

        $moveQuantities = [];

        foreach ($this->stockLines($returnTransfer) as $line) {
            $move = $receiptOperation->moves->firstWhere('product_id', $line->product_id);

            if (! $move || (float) $move->product_uom_qty < (float) $line->quantity - 0.0001) {
                return null;
            }

            $moveQuantities[$move->getKey()] = (float) $line->quantity;
        }

        if ($moveQuantities === []) {
            return null;
        }

        try {
            $returnOperation = app(TransferWorkflow::class)
                ->createReturn($receiptOperation, $moveQuantities);
        } catch (Throwable) {
            return null;
        }

        $returnOperation->forceFill([
            'branch_transfer_sale_id' => $returnTransfer->getKey(),
            'source_branch_id'        => $returnTransfer->source_branch_id,
            'destination_branch_id'   => $returnTransfer->destination_branch_id,
        ])->save();

        foreach ($returnOperation->moves as $move) {
            $line = $returnTransfer->lines->firstWhere('product_id', $move->product_id);

            $move->forceFill([
                'branch_transfer_sale_id' => $returnTransfer->getKey(),
                'branch_id'               => $returnTransfer->source_branch_id,
                'price_unit'              => $line ? (float) $line->transfer_price_unit : $move->price_unit,
            ])->save();

            if ($line) {
                $line->forceFill(['inventory_move_id' => $move->getKey()])->save();
            }
        }

        $returnTransfer->forceFill(['operation_id' => $returnOperation->getKey()])->save();

        return $returnOperation->refresh();
    }

    protected function createOperation(
        BranchTransferSale $transfer,
        OperationType $type,
        Location $sourceLocation,
        Location $destinationLocation,
        Branch $source,
        Branch $destination,
    ): Operation {
        $operation = Operation::create([
            'origin'                  => $transfer->name,
            'operation_type_id'       => $type->getKey(),
            'source_location_id'      => $sourceLocation->getKey(),
            'destination_location_id' => $destinationLocation->getKey(),
            'company_id'              => $transfer->company_id,
            'creator_id'              => Auth::id(),
            'user_id'                 => Auth::id(),
        ]);

        $operation->forceFill([
            'branch_transfer_sale_id' => $transfer->getKey(),
            'source_branch_id'        => $source->getKey(),
            'destination_branch_id'   => $destination->getKey(),
        ])->save();

        return $operation->refresh();
    }

    protected function createMove(
        Operation $operation,
        BranchTransferSaleLine $line,
        float $quantity,
        Branch $branch,
        BranchTransferSale $transfer,
    ): Move {
        $move = $operation->moves()->create([
            'product_id'              => $line->product_id,
            'product_uom_qty'         => $quantity,
            'product_qty'             => $quantity,
            'quantity'                => 0,
            'uom_id'                  => $line->product->uom_id,
            'source_location_id'      => $operation->source_location_id,
            'destination_location_id' => $operation->destination_location_id,
            'operation_type_id'       => $operation->operation_type_id,
            'warehouse_id'            => $operation->operationType->warehouse_id,
            'company_id'              => $transfer->company_id,
            'price_unit'              => (float) $line->transfer_price_unit,
            'scheduled_at'            => now(),
        ]);

        $move->forceFill([
            'branch_transfer_sale_id' => $transfer->getKey(),
            'branch_id'               => $branch->getKey(),
        ])->save();

        return $move->refresh();
    }

    /**
     * Shrink a reserved move to a smaller shipped quantity: release
     * the reservation, resize the demand, and reserve again. The
     * unshipped remainder stays at the source branch, unreserved.
     */
    protected function resizeMoveForPartialShip(Move $move, float $shipped): void
    {
        $move->refresh();
        $move->load('lines');

        app(MoveReserver::class)->release(collect([$move]));

        $move->refresh();

        $move->forceFill([
            'product_uom_qty' => $shipped,
            'product_qty'     => $shipped,
        ])->save();

        $move->refresh();

        app(MoveReserver::class)->reserve(collect([$move]));

        $move->refresh();

        if ($move->state !== MoveState::ASSIGNED) {
            throw ValidationException::withMessages([
                'lines' => "Could not reserve {$shipped} units of {$move->product?->name} for shipping.",
            ]);
        }
    }
}
