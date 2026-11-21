<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Branch\Database\Seeders\DatabaseSeeder;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Services\BranchService;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('branches');

    /*
     * The install above may skip its seeders: InstallCommand::runSeeders()
     * asks what to do when Package::$plugins (a static cache loaded at boot)
     * still says the plugin is installed after TestBootstrapHelper ran
     * migrate:fresh in the same process, and the non-interactive default
     * answer is "Skip". Seed explicitly, inside this test's transaction,
     * so every test sees the permissions and demo branches. Both seeders
     * are idempotent, so re-seeding when the install did seed is harmless.
     */
    Artisan::call('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--force' => true,
    ]);

    // The test bootstrap installs the plugin (and its permissions) in-process;
    // Spatie caches the permission list, so flush it before asserting on it.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->admin = User::query()->firstOrFail();

    Auth::login($this->admin);

    $this->company = Company::query()->firstOrFail();

    $this->service = app(BranchService::class);
});

function branchData(array $overrides = []): array
{
    return array_merge([
        'code' => 'CTG-01',
        'name' => 'Chattogram Showroom',
        'type' => BranchType::Sub->value,
        'city' => 'Chattogram',
    ], $overrides);
}

it('creates a partner and a warehouse when a sub branch is created', function () {
    $branch = $this->service->create(branchData());

    expect($branch->getKey())->not->toBeNull()
        ->and($branch->type)->toBe(BranchType::Sub)
        ->and($branch->is_main)->toBeFalse();

    expect($branch->partner_id)->not->toBeNull();

    $partner = $branch->partner()->withoutGlobalScopes()->first();

    expect($partner)->not->toBeNull()
        ->and((int) $partner->customer_rank)->toBe(1)
        ->and((int) $partner->supplier_rank)->toBe(1)
        ->and((int) $partner->branch_id)->toBe($branch->getKey());

    expect($branch->warehouse_id)->not->toBeNull();

    $warehouse = $branch->warehouse()->withoutGlobalScopes()->first();

    expect($warehouse)->not->toBeNull()
        ->and((int) $warehouse->branch_id)->toBe($branch->getKey())
        ->and((int) $warehouse->company_id)->toBe($this->company->id);
});

it('rejects a duplicate branch code for the same company', function () {
    $this->service->create(branchData(['code' => 'DUP-01', 'name' => 'First Branch']));

    $this->service->create(branchData(['code' => 'DUP-01', 'name' => 'Second Branch']));
})->throws(ValidationException::class);

it('rejects a duplicate branch name for the same company', function () {
    $this->service->create(branchData(['code' => 'DUP-02', 'name' => 'Same Name']));

    $this->service->create(branchData(['code' => 'DUP-03', 'name' => 'Same Name']));
})->throws(ValidationException::class);

it('rejects a second main branch for the same company', function () {
    $hasMain = Branch::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('type', BranchType::Main->value)
        ->exists();

    if (! $hasMain) {
        $this->service->create(branchData([
            'code' => 'MAIN-01',
            'name' => 'First Main Branch',
            'type' => BranchType::Main->value,
        ]));
    }

    $this->service->create(branchData([
        'code' => 'MAIN-02',
        'name' => 'Second Main Branch',
        'type' => BranchType::Main->value,
    ]));
})->throws(ValidationException::class);

it('creates a service branch without a warehouse', function () {
    $branch = $this->service->create(branchData([
        'code' => 'CNC-02',
        'name' => 'CNC Service 2',
        'type' => BranchType::Service->value,
    ]));

    expect($branch->type)->toBe(BranchType::Service)
        ->and($branch->warehouse_id)->toBeNull()
        ->and($branch->partner_id)->not->toBeNull();
});

it('does not allow an inactive branch as a transfer destination', function () {
    $inactive = $this->service->create(branchData([
        'code'      => 'INACT-01',
        'name'      => 'Closed Showroom',
        'is_active' => false,
    ]));

    $active = $this->service->create(branchData([
        'code' => 'ACT-01',
        'name' => 'Open Showroom',
    ]));

    expect($inactive->canBeTransferDestination())->toBeFalse()
        ->and($active->canBeTransferDestination())->toBeTrue();
});

it('grants the branch manager access to the branch', function () {
    $branch = $this->service->create(branchData([
        'manager_user_id' => $this->admin->getKey(),
    ]));

    $pivot = DB::table('branch_user')
        ->where('branch_id', $branch->getKey())
        ->where('user_id', $this->admin->getKey())
        ->first();

    expect($pivot)->not->toBeNull()
        ->and((bool) $pivot->is_manager)->toBeTrue();
});

it('loads the branch list page in the admin panel', function () {
    $this->admin->givePermissionTo('view_any_branch_branch');

    $this->actingAs($this->admin)
        ->get('/admin/branches')
        ->assertOk();
});

it('limits a branch user to their assigned branches', function () {
    $branch = $this->service->create(branchData(['code' => 'SCOPE-01', 'name' => 'Scoped Showroom']));

    $unassigned = $this->service->create(branchData(['code' => 'SCOPE-02', 'name' => 'Other Showroom']));

    $otherUser = User::create([
        'name'               => 'Scoped User',
        'email'              => 'scoped-user-'.uniqid().'@example.com',
        'password'           => Hash::make('password'),
        'default_company_id' => $this->company->id,
    ]);

    $this->service->grantAccess($branch, $otherUser->getKey());

    Auth::login($otherUser);

    $visible = Branch::query()->pluck('code')->all();

    expect($visible)->toContain('SCOPE-01')
        ->and($visible)->not->toContain('SCOPE-02');
});
