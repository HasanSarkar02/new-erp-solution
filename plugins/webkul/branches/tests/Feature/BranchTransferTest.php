<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Branch\Database\Seeders\DatabaseSeeder;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Enums\TransferType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferPriceRule;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Services\BranchService;
use Webkul\Branch\Services\BranchTransferPriceService;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

require_once __DIR__.'/../../../support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function () {
    TestBootstrapHelper::ensurePluginInstalled('branches');

    Artisan::call('db:seed', [
        '--class' => DatabaseSeeder::class,
        '--force' => true,
    ]);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->admin = User::query()->firstOrFail();

    $this->admin->givePermissionTo([
        'view_branch', 'view_any_branch', 'view_any_branch_branch',
        'view_transfer', 'create_transfer', 'edit_transfer', 'delete_transfer',
        'confirm_transfer', 'ship_transfer', 'receive_transfer', 'cancel_transfer',
        'return_transfer', 'view_all_branches', 'view_any_branch_transfer_sale',
    ]);

    Auth::login($this->admin);

    $this->company = Company::query()->firstOrFail();
    $this->main = Branch::withoutGlobalScopes()->where('type', 'main')->firstOrFail();
    $this->sub = Branch::withoutGlobalScopes()->where('code', 'DHK-01')->firstOrFail();
    $this->service = app(BranchTransferService::class);
    $this->priceService = app(BranchTransferPriceService::class);
});

function transferProduct(int $companyId, int $adminId, array $overrides = []): Product
{
    return Product::factory()->create(array_merge([
        'company_id'      => $companyId,
        'creator_id'      => $adminId,
        'cost'            => 100,
        'price'           => 150,
        'is_storable'     => true,
        'is_configurable' => false,
    ], $overrides));
}

function transferData(Product $product, Branch $main, Branch $sub, array $overrides = []): array
{
    return array_merge([
        'source_branch_id'      => $main->getKey(),
        'destination_branch_id' => $sub->getKey(),
        'lines'                 => [
            ['product_id' => $product->getKey(), 'quantity' => 5],
        ],
    ], $overrides);
}

/** Put on-hand stock for a product at a branch warehouse. */
function stockAt(Product $product, Branch $branch, float $qty): void
{
    $locationId = $branch->warehouse->lot_stock_location_id;

    ProductQuantity::query()->create([
        'product_id'  => $product->getKey(),
        'location_id' => $locationId,
        'quantity'    => $qty,
    ]);
}

function countStockAndAccounting(): array
{
    return [
        'operations'    => DB::table('inventories_operations')->count(),
        'moves'         => DB::table('inventories_moves')->count(),
        'account_moves' => DB::table('accounts_account_moves')->count(),
    ];
}

it('resolves prices in order: product rule > category rule > markup > cost', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());

    expect($this->priceService->suggestPrice($product, $this->sub))->toBe(100.0);

    BranchTransferPriceRule::create([
        'company_id'     => $this->company->id,
        'category_id'    => $product->category_id,
        'markup_percent' => 10,
        'creator_id'     => $this->admin->getKey(),
    ]);

    // Category rule only applies when the product has a category.
    if ($product->category_id) {
        expect($this->priceService->suggestPrice($product, $this->sub))->toBe(110.0);
    }

    BranchTransferPriceRule::create([
        'company_id'     => $this->company->id,
        'product_id'     => $product->getKey(),
        'transfer_price' => 150,
        'creator_id'     => $this->admin->getKey(),
    ]);

    expect($this->priceService->suggestPrice($product, $this->sub))->toBe(150.0);

    BranchTransferPriceRule::create([
        'company_id'            => $this->company->id,
        'product_id'            => $product->getKey(),
        'destination_branch_id' => $this->sub->getKey(),
        'transfer_price'        => 180,
        'creator_id'            => $this->admin->getKey(),
    ]);

    expect($this->priceService->suggestPrice($product, $this->sub))->toBe(180.0);
});

it('keeps a per-line price override after confirm', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());
    stockAt($product, $this->main, 10);

    $transfer = $this->service->create(transferData($product, $this->main, $this->sub, [
        'lines' => [
            ['product_id' => $product->getKey(), 'quantity' => 5, 'transfer_price_unit' => 175],
        ],
    ]));

    expect($transfer->amount_total)->toBe('875.0000')
        ->and($transfer->lines->first()->transfer_price_unit)->toBe('175.0000');

    $confirmed = $this->service->confirm($transfer);

    expect($confirmed->status)->toBe(TransferStatus::Confirmed)
        ->and($confirmed->lines->first()->transfer_price_unit)->toBe('175.0000')
        ->and($confirmed->amount_total)->toBe('875.0000');
});

it('numbers transfers sequentially from the ITS sequence', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());

    $first = $this->service->create(transferData($product, $this->main, $this->sub));
    $second = $this->service->create(transferData($product, $this->main, $this->sub));

    expect($first->name)->toStartWith('ITS/'.now()->format('Y').'/')
        ->and($second->name)->toStartWith('ITS/'.now()->format('Y').'/')
        ->and($second->name)->not->toBe($first->name);
});

it('rejects invalid transfers', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());

    // Same source and destination.
    $this->service->create(transferData($product, $this->main, $this->sub, [
        'destination_branch_id' => $this->main->getKey(),
    ]));
})->throws(ValidationException::class);

it('rejects a zero-quantity line', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());

    $this->service->create(transferData($product, $this->main, $this->sub, [
        'lines' => [['product_id' => $product->getKey(), 'quantity' => 0]],
    ]));
})->throws(ValidationException::class);

it('rejects an inactive destination', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());

    $closed = app(BranchService::class)->create([
        'code'      => 'CLOSED-01',
        'name'      => 'Closed Showroom',
        'type'      => 'sub',
        'is_active' => false,
    ]);

    $this->service->create(transferData($product, $this->main, $this->sub, [
        'destination_branch_id' => $closed->getKey(),
    ]));
})->throws(ValidationException::class);

it('rejects confirm when the source branch is short on stock', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());

    $transfer = $this->service->create(transferData($product, $this->main, $this->sub));

    $this->service->confirm($transfer);
})->throws(ValidationException::class);

it('moves stock on confirm, ship and receive, and posts no accounting', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());
    stockAt($product, $this->main, 10);

    $before = countStockAndAccounting();

    $transfer = $this->service->create(transferData($product, $this->main, $this->sub));

    // Draft: no stock effect yet.
    expect(countStockAndAccounting())->toBe($before)
        ->and($transfer->status)->toBe(TransferStatus::Draft)
        ->and($transfer->operation_id)->toBeNull()
        ->and($transfer->out_invoice_id)->toBeNull()
        ->and($transfer->in_invoice_id)->toBeNull();

    // Confirm: outbound Operation created, stock reserved at Main.
    $transfer = $this->service->confirm($transfer);

    expect($transfer->status)->toBe(TransferStatus::Confirmed)
        ->and($transfer->operation_id)->not->toBeNull()
        ->and(DB::table('inventories_operations')->count())->toBe($before['operations'] + 1)
        ->and((float) ProductQuantity::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $this->main->warehouse->lot_stock_location_id)
            ->sum('reserved_quantity'))->toBe(5.0);

    // Ship: Main stock falls, goods sit in transit.
    $transfer = $this->service->ship($transfer);

    expect($transfer->status)->toBe(TransferStatus::InTransit)
        ->and((float) $transfer->lines->first()->quantity_shipped)->toBe(5.0)
        ->and((float) ProductQuantity::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $this->main->warehouse->lot_stock_location_id)
            ->sum('quantity'))->toBe(5.0);

    // Receive: Sub stock rises by the received quantity.
    $transfer = $this->service->receive($transfer);

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and((float) $transfer->lines->first()->quantity_received)->toBe(5.0)
        ->and((float) ProductQuantity::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $this->sub->warehouse->lot_stock_location_id)
            ->sum('quantity'))->toBe(5.0)
        ->and(DB::table('accounts_account_moves')->count())->toBe($before['account_moves']);

    expect($transfer->histories()->count())->toBeGreaterThanOrEqual(4);
});

it('supports partial receipt', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());
    stockAt($product, $this->main, 10);

    $transfer = $this->service->create(transferData($product, $this->main, $this->sub));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);

    $lineId = $transfer->lines->first()->getKey();

    $transfer = $this->service->receive($transfer, null, [$lineId => 3]);

    expect($transfer->status)->toBe(TransferStatus::PartiallyReceived)
        ->and((float) $transfer->lines->first()->quantity_received)->toBe(3.0);

    $transfer = $this->service->receive($transfer, null, [$lineId => 2]);

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and((float) $transfer->lines->first()->quantity_received)->toBe(5.0);
});

it('rejects receiving more than shipped', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());
    stockAt($product, $this->main, 10);

    $transfer = $this->service->create(transferData($product, $this->main, $this->sub));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);

    $lineId = $transfer->lines->first()->getKey();

    $this->service->receive($transfer, null, [$lineId => 6]);
})->throws(ValidationException::class);

it('requires Main approval for Sub to Sub transfers', function () {
    $otherSub = app(BranchService::class)->create([
        'code' => 'UTT-01',
        'name' => 'Uttara Showroom',
        'type' => 'sub',
    ]);

    $product = transferProduct($this->company->id, $this->admin->getKey());
    stockAt($product, $this->sub, 10);

    $transfer = $this->service->create([
        'source_branch_id'      => $this->sub->getKey(),
        'destination_branch_id' => $otherSub->getKey(),
        'type'                  => TransferType::Rebalance->value,
        'lines'                 => [
            ['product_id' => $product->getKey(), 'quantity' => 2],
        ],
    ]);

    expect($transfer->type)->toBe(TransferType::Rebalance)
        ->and($transfer->requiresMainApproval())->toBeTrue();

    $this->service->confirm($transfer);
})->throws(ValidationException::class);

it('confirms a Sub to Sub transfer after Main approval', function () {
    $otherSub = app(BranchService::class)->create([
        'code' => 'UTT-02',
        'name' => 'Uttara Showroom 2',
        'type' => 'sub',
    ]);

    $product = transferProduct($this->company->id, $this->admin->getKey());
    stockAt($product, $this->sub, 10);

    $transfer = $this->service->create([
        'source_branch_id'      => $this->sub->getKey(),
        'destination_branch_id' => $otherSub->getKey(),
        'type'                  => TransferType::Rebalance->value,
        'lines'                 => [
            ['product_id' => $product->getKey(), 'quantity' => 2],
        ],
    ]);

    $transfer = $this->service->approve($transfer);

    expect($transfer->isApproved())->toBeTrue();

    $transfer = $this->service->confirm($transfer);

    expect($transfer->status)->toBe(TransferStatus::Confirmed);
});

it('cancels a draft without any stock or accounting effect', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());
    $before = countStockAndAccounting();

    $transfer = $this->service->create(transferData($product, $this->main, $this->sub));
    $transfer = $this->service->cancel($transfer, null, 'No longer needed.');

    expect($transfer->status)->toBe(TransferStatus::Cancelled)
        ->and(countStockAndAccounting())->toBe($before);
});

it('creates a return transfer at the original price after receipt', function () {
    $product = transferProduct($this->company->id, $this->admin->getKey());
    stockAt($product, $this->main, 10);

    $transfer = $this->service->create(transferData($product, $this->main, $this->sub, [
        'lines' => [
            ['product_id' => $product->getKey(), 'quantity' => 4, 'transfer_price_unit' => 175],
        ],
    ]));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);
    $transfer = $this->service->receive($transfer);

    $returned = $this->service->return($transfer);

    expect($returned->status)->toBe(TransferStatus::Returned);

    $returnTransfer = BranchTransferSale::query()
        ->where('parent_transfer_id', $transfer->getKey())
        ->firstOrFail();

    expect($returnTransfer->type)->toBe(TransferType::Return)
        ->and((int) $returnTransfer->source_branch_id)->toBe((int) $this->sub->getKey())
        ->and((int) $returnTransfer->destination_branch_id)->toBe((int) $this->main->getKey())
        ->and((float) $returnTransfer->lines->first()->quantity)->toBe(4.0)
        ->and($returnTransfer->lines->first()->transfer_price_unit)->toBe('175.0000');
});

it('loads the transfer list page in the admin panel', function () {
    $this->actingAs($this->admin)
        ->get('/admin/branch-transfer-sales')
        ->assertOk();
});

it('shows the Branches navigation group with both resources', function () {
    $response = $this->actingAs($this->admin)->get('/admin/branches');

    $response->assertOk();

    $html = $response->getContent();

    expect($html)
        ->toContain('/admin/branches')
        ->toContain('/admin/branch-transfer-sales')
        ->toContain('Branches');
});
