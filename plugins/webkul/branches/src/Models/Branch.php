<?php

namespace Webkul\Branch\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Webkul\Branch\Database\Factories\BranchFactory;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Models\Scopes\BranchScope;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Country;
use Webkul\Support\Models\State;
use Webkul\Support\Traits\BelongsToCompany;

/**
 * An operating branch of one legal company (plan §4.1, §4.3).
 *
 * Branches are a dimension under a single Company row — they are NOT
 * child companies. Exactly one Main branch exists per company.
 */
class Branch extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $table = 'branches';

    protected $fillable = [
        'company_id',
        'partner_id',
        'warehouse_id',
        'manager_user_id',
        'code',
        'name',
        'type',
        'is_main',
        'is_active',
        'is_demo',
        'phone',
        'mobile',
        'email',
        'street1',
        'street2',
        'city',
        'zip',
        'state_id',
        'country_id',
        'opening_balance',
        'creator_id',
    ];

    protected $casts = [
        'type'            => BranchType::class,
        'is_main'         => 'boolean',
        'is_active'       => 'boolean',
        'is_demo'         => 'boolean',
        'opening_balance' => 'decimal:2',
    ];

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user')
            ->withPivot('is_manager')
            ->withTimestamps();
    }

    public function isMain(): bool
    {
        return $this->type === BranchType::Main;
    }

    public function isSub(): bool
    {
        return $this->type === BranchType::Sub;
    }

    public function isService(): bool
    {
        return $this->type === BranchType::Service;
    }

    /**
     * Whether this branch can receive an internal transfer (plan §7.1).
     *
     * Inactive branches never can. Service branches hold no stock in
     * Module 1, so they have no warehouse and cannot receive stock.
     */
    public function canBeTransferDestination(): bool
    {
        return $this->is_active && $this->warehouse_id !== null;
    }

    /**
     * Branches the given user may act for (plan §7.4).
     *
     * Users with `view_all_branches` see everything; everyone else sees
     * only their assigned branches. The global BranchScope enforces the
     * same rule on plain queries.
     */
    public function scopeAccessibleBy($query, User $user)
    {
        if ($user->can('view_all_branches')) {
            return $query;
        }

        return $query->whereIn(
            $this->getTable().'.id',
            DB::table('branch_user')->where('user_id', $user->getKey())->pluck('branch_id')
        );
    }
}
