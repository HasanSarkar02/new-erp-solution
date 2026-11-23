<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Webkul\Branch\Enums\WriteOffStatus;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Stock write-off / shortage document (addendum A3, Phase 5).
 *
 * Goods lost or missing at a Sub branch. The Sub still owes Main
 * (payable does not fall) and posts the loss at stock value P; the
 * debt falls only if Main formally forgives it (a separate approved
 * credit note). Lifecycle: draft → approved (Main) → posted
 * (stock + ledger moved) → forgiven (optional, Main).
 */
class BranchStockWriteOff extends Model
{
    use SoftDeletes;

    protected $table = 'branch_stock_write_offs';

    protected $fillable = [
        'company_id',
        'branch_id',
        'transfer_id',
        'name',
        'status',
        'investigation_note',
        'amount_total',
        'approved_by',
        'approved_at',
        'posted_at',
        'forgiven_by',
        'forgiven_at',
        'account_move_id',
        'forgiveness_move_id',
        'is_demo',
        'creator_id',
    ];

    protected $casts = [
        'status'       => WriteOffStatus::class,
        'amount_total' => 'decimal:4',
        'approved_at'  => 'datetime',
        'posted_at'    => 'datetime',
        'forgiven_at'  => 'datetime',
        'is_demo'      => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** The transfer the lost goods came from (if known). */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(BranchTransferSale::class, 'transfer_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BranchStockWriteOffLine::class, 'write_off_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function forgiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forgiven_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function isPosted(): bool
    {
        return in_array($this->status, [WriteOffStatus::Posted, WriteOffStatus::Forgiven], true);
    }
}
