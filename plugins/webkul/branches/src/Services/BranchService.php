<?php

namespace Webkul\Branch\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Webkul\Account\Models\Partner as AccountPartner;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Models\Branch;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Partner\Enums\AccountType;
use Webkul\Partner\Models\Partner;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Branch creation and lifecycle (plan §4.5).
 *
 * Every side-effect of creating a branch lives here, inside one DB
 * transaction — never in Filament form code or in a controller:
 * Partner creation, Warehouse creation/linking, manager access grant.
 */
class BranchService
{
    /**
     * Create a branch with all its side-effects.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Branch
    {
        $actor ??= Auth::user();

        $companyId = $data['company_id']
            ?? current_company_id()
            ?? Company::query()->orderBy('id')->value('id');

        if (! $companyId) {
            throw ValidationException::withMessages([
                'company_id' => 'No company exists yet. Create a company first.',
            ]);
        }

        $data['company_id'] = (int) $companyId;
        $data['creator_id'] ??= $actor?->getKey() ?? User::query()->orderBy('id')->value('id');
        $data['is_active'] ??= true;
        $data['is_demo'] ??= false;
        $data['is_main'] = (bool) ($data['is_main'] ?? ($data['type'] ?? null) === BranchType::Main->value);

        $type = BranchType::tryFrom($data['type'] ?? '') ?? BranchType::Sub;
        $data['type'] = $type->value;

        $this->validate($data);

        return DB::transaction(function () use ($data, $type, $actor) {
            $partner = $this->createPartner($data);

            $branch = Branch::withoutGlobalScopes()->create([
                ...$data,
                'partner_id' => $partner->getKey(),
            ]);

            $this->linkPartnerToBranch($partner, $branch);

            if ($type->holdsStock()) {
                $warehouse = $this->resolveWarehouse($data, $branch);
                $branch->forceFill(['warehouse_id' => $warehouse->getKey()])->save();
            }

            if (! empty($data['manager_user_id'])) {
                $this->grantAccess($branch, (int) $data['manager_user_id'], true);
            }

            if ($actor) {
                $this->grantAccess($branch, $actor->getKey(), $branch->isMain());
            }

            return $branch->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function validate(array $data): void
    {
        $companyId = (int) $data['company_id'];

        Validator::make($data, [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'code'       => [
                'required', 'string', 'max:20',
                Rule::unique('branches', 'code')->where('company_id', $companyId),
            ],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('branches', 'name')->where('company_id', $companyId),
            ],
            'type'             => ['required', Rule::enum(BranchType::class)],
            'manager_user_id'  => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id'     => ['nullable', 'integer', 'exists:inventories_warehouses,id'],
            'state_id'         => ['nullable', 'integer', 'exists:states,id'],
            'country_id'       => ['nullable', 'integer', 'exists:countries,id'],
            'email'            => ['nullable', 'email', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:255'],
            'mobile'           => ['nullable', 'string', 'max:255'],
        ])->validate();

        if (($data['type'] ?? null) === BranchType::Main->value) {
            $this->assertNoOtherMainBranch($companyId);
        }

        if (($data['type'] ?? null) === BranchType::Service->value && ! empty($data['warehouse_id'])) {
            throw ValidationException::withMessages([
                'warehouse_id' => 'A service branch holds no stock and must not have a warehouse.',
            ]);
        }

        if (! empty($data['warehouse_id'])) {
            $warehouse = Warehouse::withoutGlobalScopes()->findOrFail((int) $data['warehouse_id']);

            if ((int) $warehouse->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'The warehouse belongs to a different company.',
                ]);
            }

            if ($warehouse->branch_id !== null) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'The warehouse is already linked to another branch.',
                ]);
            }
        }
    }

    protected function assertNoOtherMainBranch(int $companyId, ?int $ignoreBranchId = null): void
    {
        $query = Branch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('type', BranchType::Main->value)
            ->where('is_main', true);

        if ($ignoreBranchId) {
            $query->where('id', '!=', $ignoreBranchId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'type' => 'This company already has a Main branch. Only one Main branch is allowed per company.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createPartner(array $data): Partner
    {
        $partnerClass = class_exists(AccountPartner::class) ? AccountPartner::class : Partner::class;

        return $partnerClass::create([
            'creator_id'       => $data['creator_id'] ?? Auth::id(),
            'account_type'     => AccountType::COMPANY->value,
            'sub_type'         => 'company',
            'name'             => $data['name'],
            'email'            => $data['email'] ?? null,
            'phone'            => $data['phone'] ?? null,
            'mobile'           => $data['mobile'] ?? null,
            'street1'          => $data['street1'] ?? null,
            'street2'          => $data['street2'] ?? null,
            'city'             => $data['city'] ?? null,
            'zip'              => $data['zip'] ?? null,
            'state_id'         => $data['state_id'] ?? null,
            'country_id'       => $data['country_id'] ?? null,
            'company_id'       => $data['company_id'],
            'customer_rank'    => 1,
            'supplier_rank'    => 1,
        ]);
    }

    protected function linkPartnerToBranch(Partner $partner, Branch $branch): void
    {
        if (! Schema::hasColumn('partners_partners', 'branch_id')) {
            return;
        }

        $partner->forceFill(['branch_id' => $branch->getKey()])->save();
    }

    /**
     * Link an existing warehouse or create the branch warehouse.
     *
     * Warehouse creation auto-creates its locations, operation types,
     * routes and rules (Warehouse::boot). `branch_id` is not in the
     * core Warehouse $fillable, so it is set with forceFill — the core
     * plugin file is never modified.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveWarehouse(array $data, Branch $branch): Warehouse
    {
        if (! empty($data['warehouse_id'])) {
            $warehouse = Warehouse::withoutGlobalScopes()->findOrFail((int) $data['warehouse_id']);
        } else {
            $warehouse = Warehouse::create([
                'name'       => $branch->name,
                'code'       => $branch->code,
                'company_id' => $branch->company_id,
                'creator_id' => $branch->creator_id,
            ]);
        }

        $warehouse->forceFill(['branch_id' => $branch->getKey()])->save();

        return $warehouse->refresh();
    }

    public function grantAccess(Branch $branch, int $userId, bool $isManager = false): void
    {
        $existing = DB::table('branch_user')
            ->where('branch_id', $branch->getKey())
            ->where('user_id', $userId)
            ->first();

        $branch->users()->syncWithoutDetaching([
            $userId => ['is_manager' => $isManager || (bool) ($existing?->is_manager ?? false)],
        ]);
    }

    /**
     * Soft-delete a branch (plan §4.5).
     *
     * Blocked while the branch warehouse still holds stock. Balance
     * checks (inter-branch receivable/payable) are enforced here once
     * Phase 4 introduces them; today the balance is always zero because
     * no transfer documents exist yet.
     */
    public function delete(Branch $branch): void
    {
        if ($branch->warehouse_id) {
            $stockOnHand = ProductQuantity::query()
                ->whereHas('location', fn ($query) => $query->where('warehouse_id', $branch->warehouse_id))
                ->sum('quantity');

            if ((float) $stockOnHand > 0) {
                throw ValidationException::withMessages([
                    'branch' => 'This branch still holds stock. Move or sell the stock before deleting the branch.',
                ]);
            }
        }

        $branch->delete();
    }
}
