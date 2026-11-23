<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Payment;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;

/**
 * One inter-branch payment (plan §5.2c).
 *
 * A commercial settlement recorded by Main: Sub pays Main amount A.
 * The row itself posts nothing — it creates TWO linked Payment legs
 * (Sub outbound + Main inbound, paired via
 * paired_internal_transfer_payment_id), each with its own ENTRY
 * Move on the Bank journal. Allocations spread A across transfers.
 */
class InterBranchPayment extends Model
{
    protected $table = 'inter_branch_payments';

    protected $fillable = [
        'company_id',
        'payer_branch_id',
        'payee_branch_id',
        'journal_id',
        'currency_id',
        'sub_payment_id',
        'main_payment_id',
        'reference',
        'memo',
        'payment_date',
        'amount',
        'creator_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount'       => 'decimal:4',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Sub branch that pays (the debtor). */
    public function payerBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'payer_branch_id');
    }

    /** Main branch that receives (the creditor). */
    public function payeeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'payee_branch_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** Payment leg, Sub side (outbound: Dr 211500 / Cr Bank). */
    public function subPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'sub_payment_id');
    }

    /** Payment leg, Main side (inbound: Dr Bank / Cr 121500). */
    public function mainPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'main_payment_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InterBranchPaymentAllocation::class, 'payment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
