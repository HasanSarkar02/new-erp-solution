<?php

namespace Webkul\Branch\Services;

use Illuminate\Validation\ValidationException;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSaleLine;
use Webkul\Branch\Models\StockLayer;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Product\Models\Product;

/**
 * FIFO stock layers (plan §6).
 *
 * The layer pool is the cost truth for a branch's stock. Layers are
 * shared per (branch, product, location): every unit of on-hand
 * stock is covered by exactly one layer's remaining quantity.
 *
 * Stock that arrived before Phase 3 (purchase receipts, manual
 * quants, tests) has no layers. ensureLayers() tops the pool up to
 * the on-hand quantity as 'opening' layers at the product cost, so
 * FIFO consumption always has layers to consume. The top-up is
 * idempotent: it only creates the difference between on-hand stock
 * and layered stock.
 */
class StockLayerService
{
    /**
     * Top the layer pool up to the on-hand quantity at a branch's
     * stock location. Creates one 'opening' layer per product for
     * any stock not yet covered by layers, at the product cost.
     */
    public function ensureLayers(Branch $branch, Product $product, int $locationId): void
    {
        $onHand = (float) ProductQuantity::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $locationId)
            ->sum('quantity');

        $layered = (float) StockLayer::query()
            ->forStock($branch->getKey(), $product->getKey(), $locationId)
            ->sum('quantity_remaining');

        $missing = round($onHand - $layered, 4);

        if ($missing <= 0.0001) {
            return;
        }

        $oldestIncoming = ProductQuantity::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $locationId)
            ->where('quantity', '>', 0)
            ->min('incoming_at');

        StockLayer::create([
            'company_id'         => $branch->company_id,
            'branch_id'          => $branch->getKey(),
            'warehouse_id'       => $branch->warehouse_id,
            'location_id'        => $locationId,
            'product_id'         => $product->getKey(),
            'lot_id'             => null,
            'source_type'        => StockLayer::SOURCE_OPENING,
            'source_id'          => null,
            'quantity_initial'   => $missing,
            'quantity_remaining' => $missing,
            'unit_cost'          => round((float) ($product->cost ?? 0), 4),
            'original_cost_unit' => null,
            'currency_id'        => $branch->company?->currency_id,
            'incoming_at'        => $oldestIncoming ?? now(),
        ]);
    }

    /**
     * Consume layers oldest-first for a transfer line (plan §6.3
     * rule 3). Returns the weighted-average unit cost of the units
     * consumed, rounded to 4 decimals. Throws when the layers do
     * not cover the quantity — that must never happen after the
     * availability check at confirm, so it is a hard guard.
     */
    public function consumeFifo(Branch $branch, BranchTransferSaleLine $line, float $quantity, int $locationId): float
    {
        $remaining = round($quantity, 4);

        if ($remaining <= 0) {
            return 0.0;
        }

        $layers = StockLayer::query()
            ->available()
            ->fifo()
            ->forStock($branch->getKey(), (int) $line->product_id, $locationId)
            ->when($line->lot_id, fn ($query) => $query->where('lot_id', $line->lot_id))
            ->lockForUpdate()
            ->get();

        $costTotal = 0.0;
        $consumed = 0.0;

        foreach ($layers as $layer) {
            if ($remaining <= 0.0001) {
                break;
            }

            $take = min($remaining, (float) $layer->quantity_remaining);

            $layer->forceFill([
                'quantity_remaining' => round((float) $layer->quantity_remaining - $take, 4),
            ])->save();

            $line->consumptions()->create([
                'layer_id'  => $layer->getKey(),
                'quantity'  => $take,
                'unit_cost' => $layer->unit_cost,
            ]);

            $costTotal += $take * (float) $layer->unit_cost;
            $consumed += $take;
            $remaining = round($remaining - $take, 4);
        }

        if ($remaining > 0.0001 || $consumed <= 0) {
            throw ValidationException::withMessages([
                'lines' => "Not enough cost layers at {$branch->name} for {$line->product?->name}: short by {$remaining}.",
            ]);
        }

        return round($costTotal / $consumed, 4);
    }

    /**
     * Create the destination branch's layer for a receipt (plan
     * §6.3 rule 2): stock is valued at the transfer price P, and
     * the original FIFO cost C is kept for the Phase 4 elimination.
     */
    /**
     * Create a receipt layer for goods received at a branch.
     *
     * Normally valued at the transfer price P (unit_cost = P,
     * original_cost_unit = FIFO cost C from the source). For a
     * return receipt at Main the goods go back at their original
     * cost C, so the caller passes $unitCostOverride = C and the
     * layer is valued at C throughout — that is what the ledger
     * posts (Dr 110100 C), so layer and ledger must agree (Phase 4).
     */
    public function createReceiptLayer(BranchTransferSaleLine $line, float $quantity, Branch $destination, int $locationId, ?float $unitCostOverride = null): StockLayer
    {
        return StockLayer::create([
            'company_id'         => $destination->company_id,
            'branch_id'          => $destination->getKey(),
            'warehouse_id'       => $destination->warehouse_id,
            'location_id'        => $locationId,
            'product_id'         => $line->product_id,
            'lot_id'             => $line->lot_id,
            'source_type'        => StockLayer::SOURCE_INTERNAL_RECEIPT,
            'source_id'          => $line->getKey(),
            'quantity_initial'   => round($quantity, 4),
            'quantity_remaining' => round($quantity, 4),
            'unit_cost'          => round($unitCostOverride ?? (float) $line->transfer_price_unit, 4),
            'original_cost_unit' => $unitCostOverride !== null
                ? round($unitCostOverride, 4)
                : ($line->cost_price_unit === null ? null : round((float) $line->cost_price_unit, 4)),
            'currency_id'        => $line->transfer?->currency_id,
            'incoming_at'        => now(),
        ]);
    }

    /**
     * Undo a FIFO consumption: put the consumed quantities back on
     * their layers (cancel after ship). The consumption rows are
     * deleted — the ship is fully unwound, and the cancel is recorded
     * in the transfer history.
     */
    public function restoreConsumptions(BranchTransferSaleLine $line): void
    {
        foreach ($line->consumptions as $consumption) {
            $layer = $consumption->layer;

            if ($layer) {
                $layer->forceFill([
                    'quantity_remaining' => round((float) $layer->quantity_remaining + (float) $consumption->quantity, 4),
                ])->save();
            }

            $consumption->delete();
        }
    }

    /** Total stock value at a branch (SUM remaining × unit cost). */
    public function stockValue(Branch $branch, ?int $productId = null): float
    {
        return round((float) StockLayer::query()
            ->where('branch_id', $branch->getKey())
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->selectRaw('COALESCE(SUM(quantity_remaining * unit_cost), 0) as value')
            ->value('value'), 4);
    }

    /** On-hand quantity covered by layers, per product at a branch. */
    public function layeredQuantity(Branch $branch, int $productId, int $locationId): float
    {
        return round((float) StockLayer::query()
            ->forStock($branch->getKey(), $productId, $locationId)
            ->sum('quantity_remaining'), 4);
    }

    /**
     * Cross-check the two stock truths (plan §4.6): the inventory
     * quants and the layer pool must agree in quantity. Returns the
     * difference (quants − layers); 0 means they agree.
     */
    public function quantityDrift(Branch $branch, Product $product, int $locationId): float
    {
        $onHand = (float) ProductQuantity::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $locationId)
            ->sum('quantity');

        return round($onHand - $this->layeredQuantity($branch, $product->getKey(), $locationId), 4);
    }
}
