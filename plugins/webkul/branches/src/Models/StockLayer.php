<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\Lot;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Product\Models\Product;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * One batch of stock at a branch (plan §4.3, §6).
 *
 * The layer is the cost truth: stock value at a branch is
 * SUM(quantity_remaining × unit_cost). A layer's unit_cost is the
 * actual batch cost — the purchase price for a purchase receipt, or
 * the transfer price P for an internal receipt (whose
 * original_cost_unit holds Main's FIFO cost C, for the Phase 4
 * elimination). Layers are consumed oldest-first (incoming_at ASC).
 */
class StockLayer extends Model
{
    protected $table = 'stock_layers';

    public const SOURCE_OPENING = 'opening';

    public const SOURCE_INTERNAL_RECEIPT = 'internal_receipt';

    public const SOURCE_PURCHASE_RECEIPT = 'purchase_receipt';

    public const SOURCE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'company_id',
        'branch_id',
        'warehouse_id',
        'location_id',
        'product_id',
        'lot_id',
        'source_type',
        'source_id',
        'quantity_initial',
        'quantity_remaining',
        'unit_cost',
        'original_cost_unit',
        'currency_id',
        'incoming_at',
    ];

    protected $casts = [
        'quantity_initial'   => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
        'unit_cost'          => 'decimal:4',
        'original_cost_unit' => 'decimal:4',
        'incoming_at'        => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(StockLayerConsumption::class, 'layer_id');
    }

    /** Layers with stock left, in FIFO order (plan §6: incoming_at ASC, id ASC). */
    public function scopeAvailable($query)
    {
        return $query->where('quantity_remaining', '>', 0);
    }

    public function scopeFifo($query)
    {
        return $query->orderBy('incoming_at')->orderBy('id');
    }

    public function scopeForStock($query, int $branchId, int $productId, int $locationId)
    {
        return $query->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('location_id', $locationId);
    }

    /** Stock value held in this layer (remaining qty × unit cost). */
    public function value(): float
    {
        return round((float) $this->quantity_remaining * (float) $this->unit_cost, 4);
    }
}
