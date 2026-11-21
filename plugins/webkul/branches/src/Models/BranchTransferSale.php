<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Enums\TransferType;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * Internal Transfer Sale document (plan §4.3, §7.1).
 *
 * The commercial document for a Main → Sub (or Sub → Sub, or Sub → Main
 * return) transfer. In Phase 2 it is document + pricing only: creating
 * and confirming it moves no stock and posts no accounting. Stock
 * movement attaches in Phase 3 (operation_id), accounting in Phase 4
 * (out_invoice_id / in_invoice_id).
 */
class BranchTransferSale extends Model
{
    use SoftDeletes;

    protected $table = 'branch_transfer_sales';

    protected $fillable = [
        'company_id',
        'source_branch_id',
        'destination_branch_id',
        'source_warehouse_id',
        'destination_warehouse_id',
        'operation_id',
        'out_invoice_id',
        'in_invoice_id',
        'parent_transfer_id',
        'name',
        'reference',
        'type',
        'status',
        'transfer_date',
        'expected_date',
        'received_date',
        'currency_id',
        'amount_untaxed',
        'amount_total',
        'amount_cost_total',
        'amount_paid',
        'amount_residual',
        'payment_terms_days',
        'due_date',
        'approved_by',
        'approved_at',
        'notes',
        'creator_id',
        'created_by_branch_id',
    ];

    protected $casts = [
        'type'              => TransferType::class,
        'status'            => TransferStatus::class,
        'transfer_date'     => 'date',
        'expected_date'     => 'date',
        'received_date'     => 'datetime',
        'due_date'          => 'date',
        'approved_at'       => 'datetime',
        'amount_untaxed'    => 'decimal:4',
        'amount_total'      => 'decimal:4',
        'amount_cost_total' => 'decimal:4',
        'amount_paid'       => 'decimal:4',
        'amount_residual'   => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transfer) {
            if (filled($transfer->name)) {
                return;
            }

            $transfer->name = app(BranchTransferService::class)
                ->generateNumber(
                    (int) $transfer->company_id,
                    $transfer->transfer_date ? Carbon::parse($transfer->transfer_date) : now(),
                );
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function parentTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transfer_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BranchTransferSaleLine::class, 'transfer_id')->orderBy('sort');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BranchTransferSaleHistory::class, 'transfer_id')->orderBy('created_at');
    }

    /**
     * Sub → Sub (rebalance) transfers need Main approval before ship
     * (addendum A2). A transfer is approved when approved_by is set.
     */
    public function requiresMainApproval(): bool
    {
        return $this->type === TransferType::Rebalance;
    }

    public function isApproved(): bool
    {
        return ! $this->requiresMainApproval() || $this->approved_by !== null;
    }

    /**
     * Recompute the cached totals from the lines. Cost totals stay 0
     * until Phase 3 captures the FIFO cost (C) at ship time.
     * Query-based sums, so it works even with a stale lines relation.
     */
    public function recomputeTotals(): void
    {
        $untaxed = (float) $this->lines()->sum('price_subtotal');
        $cost = (float) $this->lines()->sum('cost_subtotal');

        $this->forceFill([
            'amount_untaxed'    => $untaxed,
            'amount_total'      => $untaxed,
            'amount_cost_total' => $cost,
            'amount_residual'   => $untaxed - (float) $this->amount_paid,
        ])->save();
    }

    public function isFullyReceived(): bool
    {
        return $this->lines->every(
            fn (BranchTransferSaleLine $line) => (float) $line->quantity_received >= (float) $line->quantity
        );
    }

    public function isPartiallyReceived(): bool
    {
        return $this->lines->contains(
            fn (BranchTransferSaleLine $line) => (float) $line->quantity_received > 0
                && (float) $line->quantity_received < (float) $line->quantity
        );
    }
}
