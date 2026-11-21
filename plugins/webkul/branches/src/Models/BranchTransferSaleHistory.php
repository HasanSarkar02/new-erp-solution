<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Security\Models\User;

class BranchTransferSaleHistory extends Model
{
    protected $table = 'branch_transfer_sale_histories';

    protected $fillable = [
        'transfer_id',
        'user_id',
        'from_status',
        'to_status',
        'note',
    ];

    protected $casts = [
        'from_status' => TransferStatus::class,
        'to_status'   => TransferStatus::class,
    ];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BranchTransferSale::class, 'transfer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
