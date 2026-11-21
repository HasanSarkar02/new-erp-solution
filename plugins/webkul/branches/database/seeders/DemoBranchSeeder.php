<?php

namespace Webkul\Branch\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Services\BranchService;
use Webkul\Support\Models\Company;

/**
 * Demo branches (plan §9 Phase 1.4).
 *
 * Clearly marked demo data (`is_demo = true`) and deletable from the
 * Branches list. The Main branch links the warehouse that already
 * exists for the company; any other unlinked warehouse of the company
 * is backfilled to the Main branch.
 */
class DemoBranchSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $company = Company::withoutGlobalScopes()->orderBy('id')->firstOrFail();

        $service = app(BranchService::class);

        $main = $this->seedBranch($service, $company->id, [
            'code'         => 'MAIN',
            'name'         => 'Main Branch',
            'type'         => BranchType::Main->value,
            'is_main'      => true,
            'warehouse_id' => DB::table('inventories_warehouses')
                ->where('company_id', $company->id)
                ->orderBy('id')
                ->value('id'),
            'is_demo' => true,
        ]);

        $this->seedBranch($service, $company->id, [
            'code'    => 'DHK-01',
            'name'    => 'Dhanmondi Showroom',
            'type'    => BranchType::Sub->value,
            'city'    => 'Dhaka',
            'is_demo' => true,
        ]);

        $this->seedBranch($service, $company->id, [
            'code'    => 'CNC-01',
            'name'    => 'CNC Service',
            'type'    => BranchType::Service->value,
            'city'    => 'Dhaka',
            'is_demo' => true,
        ]);

        DB::table('inventories_warehouses')
            ->where('company_id', $company->id)
            ->whereNull('branch_id')
            ->update(['branch_id' => $main->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function seedBranch(BranchService $service, int $companyId, array $attributes): Branch
    {
        $existing = Branch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', $attributes['code'])
            ->first();

        if ($existing) {
            return $existing;
        }

        return $service->create([
            'company_id' => $companyId,
            ...$attributes,
        ]);
    }
}
