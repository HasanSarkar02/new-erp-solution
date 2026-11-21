<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One allocation of an inter-branch payment to a transfer. */
class InterBranchPaymentAllocation extends Model
{
    protected $table = 'inter_branch_payment_allocations';

    protected $fillable = [
        'payment_id',
        'transfer_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(InterBranchPayment::class, 'payment_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BranchTransferSale::class, 'transfer_id');
    }
}
