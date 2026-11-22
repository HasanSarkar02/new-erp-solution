<?php

namespace Webkul\Branch\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Services\BranchService;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Branch\Services\InterBranchAccountingService;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Demo transfers (plan §9 Phase 5.3).
 *
 * Idempotent and clearly marked demo data (branch is_demo, product
 * names start with "Demo —"). Adds two Sub branches, one Service
 * branch and a small demo catalogue, then runs three transfers
 * through the real services so every state is clickable:
 *   - ITS paid in full (Main → Uttara, received, paid 100%)
 *   - ITS partially paid (Main → Mirpur, received, paid 40%)
 *   - ITS in transit (Main → Uttara, shipped, not received)
 * Re-running the seeder does nothing once the demo transfers exist.
 */
class DemoTransfersSeeder extends Seeder
{
    /**
     * Set true to seed demo transfers while running unit tests
     * (normally skipped — see run()).
     */
    public bool $force = false;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo data must not pollute the test suite: every branches
        // test seeds DatabaseSeeder in beforeEach, and these demo
        // transfers would post 12 account moves into every test's
        // clean slate. Production installs seed the demo; tests opt
        // in explicitly by setting $force = true.
        if (app()->runningUnitTests() && ! $this->force) {
            return;
        }

        $company = Company::withoutGlobalScopes()->orderBy('id')->firstOrFail();
        $admin = $this->adminUser();

        $main = Branch::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', BranchType::Main)
            ->firstOrFail();

        $uttara = $this->seedBranch($company->id, [
            'code'    => 'UTT-01',
            'name'    => 'Uttara Showroom',
            'type'    => BranchType::Sub->value,
            'city'    => 'Dhaka',
            'is_demo' => true,
        ]);

        $mirpur = $this->seedBranch($company->id, [
            'code'    => 'MIR-01',
            'name'    => 'Mirpur Showroom',
            'type'    => BranchType::Sub->value,
            'city'    => 'Dhaka',
            'is_demo' => true,
        ]);

        $this->seedBranch($company->id, [
            'code'    => 'SVC-01',
            'name'    => 'Furniture Repair Service',
            'type'    => BranchType::Service->value,
            'city'    => 'Dhaka',
            'is_demo' => true,
        ]);

        $products = $this->seedProducts($company->id, $admin->getKey(), $main);

        $service = app(BranchTransferService::class);
        $accounting = app(InterBranchAccountingService::class);

        // Each demo transfer is guarded on its own reference, so a
        // re-run after a partial failure finishes the missing ones
        // instead of skipping everything.
        $this->seedPaidTransfer($company, $main, $uttara, $products['chair'], $service, $accounting, $admin);
        $this->seedPartialTransfer($company, $main, $mirpur, $products['table4'], $service, $accounting, $admin);
        $this->seedInTransitTransfer($company, $main, $uttara, $products['sofaFabric'], $service, $admin);
    }

    protected function seedPaidTransfer(Company $company, Branch $main, Branch $uttara, Product $chair, BranchTransferService $service, InterBranchAccountingService $accounting, User $admin): void
    {
        if ($this->demoTransferExists($main, 'DEMO-PAID')) {
            return;
        }

        // 1. Paid in full: 5 × Demo Office Chair @ 5,000 → Uttara.
        $paid = $service->create([
            'company_id'            => $company->id,
            'source_branch_id'      => $main->getKey(),
            'destination_branch_id' => $uttara->getKey(),
            'reference'             => 'DEMO-PAID',
            'notes'                 => 'Demo transfer — paid in full.',
            'lines'                 => [
                ['product_id' => $chair->getKey(), 'quantity' => 5, 'transfer_price_unit' => 5000],
            ],
        ], $admin);

        $service->confirm($paid, $admin);
        $service->ship($paid, $admin);
        $service->receive($paid, $admin);

        $accounting->recordPayment(
            $uttara,
            $main,
            (float) $paid->refresh()->amount_total,
            now()->toDateString(),
            ['reference' => 'DEMO-PAY-1', 'allocations' => [$paid->getKey() => (float) $paid->amount_total]],
        );
    }

    protected function seedPartialTransfer(Company $company, Branch $main, Branch $mirpur, Product $table4, BranchTransferService $service, InterBranchAccountingService $accounting, User $admin): void
    {
        if ($this->demoTransferExists($main, 'DEMO-PARTIAL')) {
            return;
        }

        // 2. Partially paid: 5 × Demo Dining Table 4-Seater @ 10,000 → Mirpur, 40% paid.
        $partial = $service->create([
            'company_id'            => $company->id,
            'source_branch_id'      => $main->getKey(),
            'destination_branch_id' => $mirpur->getKey(),
            'reference'             => 'DEMO-PARTIAL',
            'notes'                 => 'Demo transfer — partially paid (40%).',
            'lines'                 => [
                ['product_id' => $table4->getKey(), 'quantity' => 5, 'transfer_price_unit' => 10000],
            ],
        ], $admin);

        $service->confirm($partial, $admin);
        $service->ship($partial, $admin);
        $service->receive($partial, $admin);

        $partialTotal = (float) $partial->refresh()->amount_total;

        $accounting->recordPayment(
            $mirpur,
            $main,
            round($partialTotal * 0.4, 2),
            now()->toDateString(),
            ['reference' => 'DEMO-PAY-2', 'allocations' => [$partial->getKey() => round($partialTotal * 0.4, 2)]],
        );
    }

    protected function seedInTransitTransfer(Company $company, Branch $main, Branch $uttara, Product $sofaFabric, BranchTransferService $service, User $admin): void
    {
        if ($this->demoTransferExists($main, 'DEMO-TRANSIT')) {
            return;
        }

        // 3. In transit: 3 × Demo Sofa Fabric Grey @ 15,000 → Uttara, shipped only.
        $inTransit = $service->create([
            'company_id'            => $company->id,
            'source_branch_id'      => $main->getKey(),
            'destination_branch_id' => $uttara->getKey(),
            'reference'             => 'DEMO-TRANSIT',
            'notes'                 => 'Demo transfer — shipped, not yet received.',
            'lines'                 => [
                ['product_id' => $sofaFabric->getKey(), 'quantity' => 3, 'transfer_price_unit' => 15000],
            ],
        ], $admin);

        $service->confirm($inTransit, $admin);
        $service->ship($inTransit, $admin);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function seedBranch(int $companyId, array $attributes): Branch
    {
        $existing = Branch::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', $attributes['code'])
            ->first();

        if ($existing) {
            return $existing;
        }

        return app(BranchService::class)->create([
            'company_id' => $companyId,
            ...$attributes,
        ]);
    }

    /**
     * Demo catalogue: two configurable parents with variants, plus
     * two simple products. Stocked 20 units each at Main so the
     * demo transfers (and HASAN's own clicks) have stock to move.
     *
     * @return array<string, Product>
     */
    protected function seedProducts(int $companyId, int $adminId, Branch $main): array
    {
        $sofa = $this->seedProduct($companyId, $adminId, [
            'name'            => 'Demo — Sofa 3-Seater',
            'reference'       => 'DEMO-SOFA',
            'cost'            => 12000,
            'price'           => 15000,
            'is_configurable' => true,
        ]);

        $sofaFabric = $this->seedProduct($companyId, $adminId, [
            'name'      => 'Demo — Sofa 3-Seater, Fabric Grey',
            'reference' => 'DEMO-SOFA-FABRIC',
            'cost'      => 12000,
            'price'     => 15000,
            'parent_id' => $sofa->getKey(),
        ]);

        $sofaLeather = $this->seedProduct($companyId, $adminId, [
            'name'      => 'Demo — Sofa 3-Seater, Leather Brown',
            'reference' => 'DEMO-SOFA-LEATHER',
            'cost'      => 16000,
            'price'     => 20000,
            'parent_id' => $sofa->getKey(),
        ]);

        $table = $this->seedProduct($companyId, $adminId, [
            'name'            => 'Demo — Dining Table',
            'reference'       => 'DEMO-TABLE',
            'cost'            => 8000,
            'price'           => 10000,
            'is_configurable' => true,
        ]);

        $table4 = $this->seedProduct($companyId, $adminId, [
            'name'      => 'Demo — Dining Table, 4-Seater',
            'reference' => 'DEMO-TABLE-4',
            'cost'      => 8000,
            'price'     => 10000,
            'parent_id' => $table->getKey(),
        ]);

        $table6 = $this->seedProduct($companyId, $adminId, [
            'name'      => 'Demo — Dining Table, 6-Seater',
            'reference' => 'DEMO-TABLE-6',
            'cost'      => 11000,
            'price'     => 14000,
            'parent_id' => $table->getKey(),
        ]);

        $chair = $this->seedProduct($companyId, $adminId, [
            'name'      => 'Demo — Office Chair',
            'reference' => 'DEMO-CHAIR',
            'cost'      => 4000,
            'price'     => 5000,
        ]);

        $tv = $this->seedProduct($companyId, $adminId, [
            'name'      => 'Demo — LED TV 43 inch',
            'reference' => 'DEMO-TV43',
            'cost'      => 28000,
            'price'     => 35000,
        ]);

        $stockLocationId = $main->warehouse->lot_stock_location_id;

        foreach ([$sofaFabric, $sofaLeather, $table4, $table6, $chair, $tv] as $product) {
            ProductQuantity::query()->firstOrCreate([
                'product_id'  => $product->getKey(),
                'location_id' => $stockLocationId,
            ], [
                'quantity'          => 20,
                'reserved_quantity' => 0,
                'incoming_at'       => now(),
            ]);
        }

        return [
            'sofa'        => $sofa,
            'sofaFabric'  => $sofaFabric,
            'sofaLeather' => $sofaLeather,
            'table'       => $table,
            'table4'      => $table4,
            'table6'      => $table6,
            'chair'       => $chair,
            'tv'          => $tv,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function seedProduct(int $companyId, int $adminId, array $attributes): Product
    {
        $existing = Product::query()
            ->where('company_id', $companyId)
            ->where('reference', $attributes['reference'])
            ->first();

        if ($existing) {
            return $existing;
        }

        return Product::factory()->create(array_merge([
            'company_id'      => $companyId,
            'creator_id'      => $adminId,
            'is_storable'     => true,
            'is_configurable' => false,
        ], $attributes));
    }

    protected function demoTransferExists(Branch $main, string $reference): bool
    {
        return BranchTransferSale::query()
            ->where('source_branch_id', $main->getKey())
            ->where('reference', $reference)
            ->exists();
    }

    protected function adminUser(): User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'Admin'))
            ->first()
            ?? User::query()->firstOrFail();
    }
}
