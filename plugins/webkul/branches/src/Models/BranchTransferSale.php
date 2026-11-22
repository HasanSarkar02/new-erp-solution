<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Webkul\Account\Models\Move;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Enums\TransferType;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Inventory\Models\Operation;
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
        'receipt_operation_id',
        'out_invoice_id',
        'in_invoice_id',
        'cogs_move_id',
        'parent_transfer_id',
        'name',
        'reference',
        'type',
        'return_reason',
        'status',
        'is_closed_short',
        'closed_short_at',
        'transfer_date',
        'expected_date',
        'received_date',
        'currency_id',
        'amount_untaxed',
        'amount_total',
        'amount_cost_total',
        'amount_paid',
        'amount_residual',
        'payment_state',
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
        'is_closed_short'   => 'boolean',
        'closed_short_at'   => 'datetime',
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

    /** Outbound stock Operation (Stock → Transit), created at confirm. */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'operation_id');
    }

    /** Latest receipt Operation (Transit → destination Stock). */
    public function receiptOperation(): BelongsTo
    {
        return $this->belongsTo(Operation::class, 'receipt_operation_id');
    }

    public function parentTransfer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_transfer_id');
    }

    /** Return transfers created from this transfer (unsold / damaged). */
    public function returnTransfers(): HasMany
    {
        return $this->hasMany(self::class, 'parent_transfer_id');
    }

    /** Lost / missing write-offs raised against this transfer. */
    public function writeOffs(): HasMany
    {
        return $this->hasMany(BranchStockWriteOff::class, 'transfer_id');
    }

    /** Out_invoice posted at ship (Main bills Sub at P). Phase 4. */
    public function outInvoice(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'out_invoice_id');
    }

    /** In_invoice posted at receive (Sub owes Main at P). Phase 4. */
    public function inInvoice(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'in_invoice_id');
    }

    /** COGS ENTRY posted at ship (Main, at FIFO cost C). Phase 4. */
    public function cogsMove(): BelongsTo
    {
        return $this->belongsTo(Move::class, 'cogs_move_id');
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
     *
     * Once an out_invoice exists (Phase 4), the invoice is the
     * commercial truth: amount_paid / amount_residual / payment_state
     * come from it, not from the ordered totals — a partial shipment
     * is only billable for what shipped.
     */
    public function recomputeTotals(): void
    {
        $untaxed = (float) $this->lines()->sum('price_subtotal');
        $cost = (float) $this->lines()->sum('cost_subtotal');

        $values = [
            'amount_untaxed'    => $untaxed,
            'amount_total'      => $untaxed,
            'amount_cost_total' => $cost,
        ];

        if ($this->out_invoice_id && $this->type !== TransferType::Return) {
            $invoice = Move::query()->find($this->out_invoice_id);

            if ($invoice) {
                $values['amount_paid'] = round((float) $invoice->amount_total - (float) $invoice->amount_residual, 4);
                $values['amount_residual'] = round((float) $invoice->amount_residual, 4);
                $values['payment_state'] = $invoice->payment_state instanceof \BackedEnum
                    ? $invoice->payment_state->value
                    : (string) $invoice->payment_state;
            }
        } else {
            $values['amount_residual'] = $untaxed - (float) $this->amount_paid;
        }

        $this->forceFill($values)->save();
    }

    /**
     * Fully received: every line received at least the ordered
     * quantity — or, when the transfer was closed short (Phase 5),
     * at least the shipped quantity. The unshipped remainder was
     * closed without delivery and never counts against receipt.
     */
    public function isFullyReceived(): bool
    {
        return $this->lines->every(function (BranchTransferSaleLine $line) {
            $target = $this->is_closed_short
                ? (float) $line->quantity_shipped
                : (float) $line->quantity;

            return (float) $line->quantity_received >= $target;
        });
    }

    public function isPartiallyReceived(): bool
    {
        return $this->lines->contains(function (BranchTransferSaleLine $line) {
            $target = $this->is_closed_short
                ? (float) $line->quantity_shipped
                : (float) $line->quantity;

            return (float) $line->quantity_received > 0
                && (float) $line->quantity_received < $target;
        });
    }
}
