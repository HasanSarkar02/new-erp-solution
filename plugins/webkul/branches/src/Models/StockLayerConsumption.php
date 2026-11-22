<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One FIFO consumption record (plan §6.3 rule 3).
 *
 * Links a shipped transfer line to the exact layer(s) its cost came
 * from, so the line's cost_price_unit is auditable back to the
 * receipt batches.
 */
class StockLayerConsumption extends Model
{
    protected $table = 'stock_layer_consumptions';

    protected $fillable = [
        'layer_id',
        'transfer_line_id',
        'write_off_line_id',
        'quantity',
        'unit_cost',
    ];

    protected $casts = [
        'quantity'  => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(StockLayer::class, 'layer_id');
    }

    public function transferLine(): BelongsTo
    {
        return $this->belongsTo(BranchTransferSaleLine::class, 'transfer_line_id');
    }

    /** The write-off line that consumed this layer (lost stock). */
    public function writeOffLine(): BelongsTo
    {
        return $this->belongsTo(BranchStockWriteOffLine::class, 'write_off_line_id');
    }
}
