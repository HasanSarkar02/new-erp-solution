<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Inventory\Models\Scrap;
use Webkul\Product\Models\Product;

class BranchStockWriteOffLine extends Model
{
    protected $table = 'branch_stock_write_off_lines';

    protected $fillable = [
        'write_off_id',
        'product_id',
        'transfer_line_id',
        'inventory_scrap_id',
        'quantity',
        'unit_value',
        'loss_subtotal',
    ];

    protected $casts = [
        'quantity'      => 'decimal:4',
        'unit_value'    => 'decimal:4',
        'loss_subtotal' => 'decimal:4',
    ];

    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(BranchStockWriteOff::class, 'write_off_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The original transfer line the lost goods were received on. */
    public function transferLine(): BelongsTo
    {
        return $this->belongsTo(BranchTransferSaleLine::class, 'transfer_line_id');
    }

    /** The engine Scrap record that moved the stock to Scrap. */
    public function scrap(): BelongsTo
    {
        return $this->belongsTo(Scrap::class, 'inventory_scrap_id');
    }

    /** The FIFO layer consumptions this write-off line caused. */
    public function consumptions(): HasMany
    {
        return $this->hasMany(StockLayerConsumption::class, 'write_off_line_id');
    }
}
