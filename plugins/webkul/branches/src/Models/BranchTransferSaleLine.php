<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Product\Models\Product;

class BranchTransferSaleLine extends Model
{
    protected $table = 'branch_transfer_sale_lines';

    protected $fillable = [
        'transfer_id',
        'product_id',
        'lot_id',
        'quantity',
        'quantity_shipped',
        'quantity_received',
        'quantity_returned',
        'uom_id',
        'transfer_price_unit',
        'credit_value_unit',
        'cost_price_unit',
        'price_subtotal',
        'cost_subtotal',
        'inventory_move_id',
        'sort',
    ];

    protected $casts = [
        'quantity'            => 'decimal:4',
        'quantity_shipped'    => 'decimal:4',
        'quantity_received'   => 'decimal:4',
        'quantity_returned'   => 'decimal:4',
        'transfer_price_unit' => 'decimal:4',
        'credit_value_unit'   => 'decimal:4',
        'cost_price_unit'     => 'decimal:4',
        'price_subtotal'      => 'decimal:4',
        'cost_subtotal'       => 'decimal:4',
    ];

    protected static function booted(): void
    {
        // Keep the parent transfer totals in sync whenever a line
        // changes, whichever path saved it (service or Filament form).
        static::saved(function (self $line) {
            $line->transfer?->recomputeTotals();
        });

        static::deleted(function (self $line) {
            $line->transfer?->recomputeTotals();
        });
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BranchTransferSale::class, 'transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** FIFO consumption records for this line (Phase 3). */
    public function consumptions(): HasMany
    {
        return $this->hasMany(StockLayerConsumption::class, 'transfer_line_id');
    }

    /** Lost / missing write-off lines raised against this line. */
    public function writeOffLines(): HasMany
    {
        return $this->hasMany(BranchStockWriteOffLine::class, 'transfer_line_id');
    }

    /**
     * Refresh the line subtotals from qty x price. Cost subtotal is
     * qty x FIFO cost, which is NULL until Phase 3 captures it at ship.
     */
    public function recomputeSubtotals(): void
    {
        $this->forceFill([
            'price_subtotal' => (float) $this->quantity * (float) $this->transfer_price_unit,
            'cost_subtotal'  => $this->cost_price_unit === null
                ? 0
                : (float) $this->quantity * (float) $this->cost_price_unit,
        ])->save();
    }
}
