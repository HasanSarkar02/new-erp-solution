<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Webkul\Product\Models\Category;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

class BranchTransferPriceRule extends Model
{
    protected $table = 'branch_transfer_price_rules';

    protected $fillable = [
        'company_id',
        'destination_branch_id',
        'product_id',
        'category_id',
        'transfer_price',
        'markup_percent',
        'effective_from',
        'effective_to',
        'is_active',
        'creator_id',
    ];

    protected $casts = [
        'transfer_price' => 'decimal:4',
        'markup_percent' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to'   => 'date',
        'is_active'      => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffectiveOn($query, $date)
    {
        return $query
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            });
    }

    /**
     * The price this rule yields for a product whose base cost is $cost.
     * A fixed transfer_price wins over markup_percent.
     */
    public function priceFor(float $cost): float
    {
        if ($this->transfer_price !== null) {
            return round((float) $this->transfer_price, 4);
        }

        if ($this->markup_percent !== null) {
            return round($cost * (1 + (float) $this->markup_percent / 100), 4);
        }

        return round($cost, 4);
    }
}
