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
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Models\StockLayer;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Inventory\Models\Location;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Product\Enums\ProductType;
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
});

function stockProduct(int $companyId, int $adminId, array $overrides = []): Product
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

function stockTransferData(Product $product, Branch $main, Branch $sub, array $overrides = []): array
{
    return array_merge([
        'source_branch_id'      => $main->getKey(),
        'destination_branch_id' => $sub->getKey(),
        'lines'                 => [
            ['product_id' => $product->getKey(), 'quantity' => 5],
        ],
    ], $overrides);
}

/** On-hand stock for a product at a branch warehouse (no layers). */
function stockAtBranch(Product $product, Branch $branch, float $qty): void
{
    ProductQuantity::query()->create([
        'product_id'  => $product->getKey(),
        'location_id' => $branch->warehouse->lot_stock_location_id,
        'quantity'    => $qty,
        'incoming_at' => now(),
        'company_id'  => $product->company_id,
    ]);
}

/** A cost layer as a purchase receipt would have created it. */
function stockLayer(Branch $branch, Product $product, float $qty, float $unitCost, $incomingAt): void
{
    StockLayer::create([
        'company_id'         => $branch->company_id,
        'branch_id'          => $branch->getKey(),
        'warehouse_id'       => $branch->warehouse_id,
        'location_id'        => $branch->warehouse->lot_stock_location_id,
        'product_id'         => $product->getKey(),
        'source_type'        => StockLayer::SOURCE_PURCHASE_RECEIPT,
        'quantity_initial'   => $qty,
        'quantity_remaining' => $qty,
        'unit_cost'          => $unitCost,
        'incoming_at'        => $incomingAt,
    ]);
}

function onHandAt(Product $product, int $locationId): float
{
    return (float) ProductQuantity::query()
        ->where('product_id', $product->getKey())
        ->where('location_id', $locationId)
        ->sum('quantity');
}

function reservedAtBranch(Product $product, Branch $branch): float
{
    return (float) ProductQuantity::query()
        ->where('product_id', $product->getKey())
        ->where('location_id', $branch->warehouse->lot_stock_location_id)
        ->sum('reserved_quantity');
}

function transitLocationId(): int
{
    return Location::query()->where('name', 'Inter Warehouse Transit')->value('id');
}

it('a draft has no stock effect', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey());
    stockAtBranch($product, $this->main, 10);

    $transfer = $this->service->create(stockTransferData($product, $this->main, $this->sub));

    expect($transfer->status)->toBe(TransferStatus::Draft)
        ->and(DB::table('inventories_operations')->count())->toBe(0)
        ->and(DB::table('inventories_moves')->count())->toBe(0)
        ->and(DB::table('stock_layers')->count())->toBe(0)
        ->and(onHandAt($product, $this->main->warehouse->lot_stock_location_id))->toBe(10.0);
});

it('reserves stock at confirm and releases it on cancel', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey());
    stockAtBranch($product, $this->main, 10);

    $transfer = $this->service->create(stockTransferData($product, $this->main, $this->sub));
    $transfer = $this->service->confirm($transfer);

    expect($transfer->operation_id)->not->toBeNull()
        ->and(reservedAtBranch($product, $this->main))->toBe(5.0)
        ->and(onHandAt($product, $this->main->warehouse->lot_stock_location_id))->toBe(10.0);

    $transfer = $this->service->cancel($transfer, null, 'Changed my mind.');

    expect($transfer->status)->toBe(TransferStatus::Cancelled)
        ->and(reservedAtBranch($product, $this->main))->toBe(0.0)
        ->and(DB::table('inventories_operations')->where('id', $transfer->operation_id)->value('state'))->toBe('canceled')
        ->and(DB::table('accounts_account_moves')->count())->toBe(0);
});

it('ships from the oldest layer first and captures the weighted cost', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey());
    stockAtBranch($product, $this->main, 12);
    stockLayer($this->main, $product, 6, 80, now()->subDays(10));
    stockLayer($this->main, $product, 6, 120, now()->subDays(2));

    $transfer = $this->service->create(stockTransferData($product, $this->main, $this->sub, [
        'lines' => [['product_id' => $product->getKey(), 'quantity' => 8, 'transfer_price_unit' => 150]],
    ]));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);

    $line = $transfer->lines()->firstOrFail();

    // 6 @ 80 + 2 @ 120 = 720 / 8 = 90.
    expect((float) $line->cost_price_unit)->toBe(90.0)
        ->and((float) $line->cost_subtotal)->toBe(720.0)
        ->and($transfer->status)->toBe(TransferStatus::InTransit)
        ->and(onHandAt($product, $this->main->warehouse->lot_stock_location_id))->toBe(4.0)
        ->and(onHandAt($product, transitLocationId()))->toBe(8.0)
        ->and(onHandAt($product, $this->sub->warehouse->lot_stock_location_id))->toBe(0.0);

    $consumptions = DB::table('stock_layer_consumptions')
        ->where('transfer_line_id', $line->getKey())
        ->orderBy('unit_cost')
        ->get();

    expect($consumptions)->toHaveCount(2)
        ->and((float) $consumptions[0]->quantity)->toBe(6.0)
        ->and((float) $consumptions[0]->unit_cost)->toBe(80.0)
        ->and((float) $consumptions[1]->quantity)->toBe(2.0)
        ->and((float) $consumptions[1]->unit_cost)->toBe(120.0);

    // The remaining layer is the newer one: 4 @ 120.
    expect((float) StockLayer::query()->where('branch_id', $this->main->getKey())->sum('quantity_remaining'))->toBe(4.0)
        ->and((float) StockLayer::query()->where('branch_id', $this->main->getKey())->where('quantity_remaining', '>', 0)->firstOrFail()->unit_cost)->toBe(120.0);
});

it('backfills an opening layer at product cost for stock without layers', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey());
    stockAtBranch($product, $this->main, 10);

    $transfer = $this->service->create(stockTransferData($product, $this->main, $this->sub));
    $transfer = $this->service->confirm($transfer);

    $layer = StockLayer::query()->where('branch_id', $this->main->getKey())->firstOrFail();

    expect($layer->source_type)->toBe(StockLayer::SOURCE_OPENING)
        ->and((float) $layer->quantity_remaining)->toBe(10.0)
        ->and((float) $layer->unit_cost)->toBe(100.0);

    $transfer = $this->service->ship($transfer);

    expect((float) $transfer->lines()->firstOrFail()->cost_price_unit)->toBe(100.0);
});

it('creates the destination layer at the transfer price on receipt', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey());
    stockAtBranch($product, $this->main, 10);
    stockLayer($this->main, $product, 10, 100, now()->subDays(5));

    $transfer = $this->service->create(stockTransferData($product, $this->main, $this->sub, [
        'lines' => [['product_id' => $product->getKey(), 'quantity' => 5, 'transfer_price_unit' => 150]],
    ]));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);
    $transfer = $this->service->receive($transfer);

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and($transfer->receipt_operation_id)->not->toBeNull()
        ->and(onHandAt($product, $this->sub->warehouse->lot_stock_location_id))->toBe(5.0)
        ->and(onHandAt($product, transitLocationId()))->toBe(0.0);

    $layer = StockLayer::query()->where('branch_id', $this->sub->getKey())->firstOrFail();

    expect((float) $layer->quantity_remaining)->toBe(5.0)
        ->and((float) $layer->unit_cost)->toBe(150.0)
        ->and((float) $layer->original_cost_unit)->toBe(100.0)
        ->and($layer->source_type)->toBe(StockLayer::SOURCE_INTERNAL_RECEIPT);
});

it('leaves the unreceived remainder in transit on partial receipt', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey());
    stockAtBranch($product, $this->main, 10);

    $transfer = $this->service->create(stockTransferData($product, $this->main, $this->sub));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);

    $lineId = $transfer->lines()->firstOrFail()->getKey();

    $transfer = $this->service->receive($transfer, null, [$lineId => 3]);

    expect($transfer->status)->toBe(TransferStatus::PartiallyReceived)
        ->and(onHandAt($product, $this->sub->warehouse->lot_stock_location_id))->toBe(3.0)
        ->and(onHandAt($product, transitLocationId()))->toBe(2.0)
        ->and(onHandAt($product, $this->main->warehouse->lot_stock_location_id))->toBe(5.0);

    $transfer = $this->service->receive($transfer, null, [$lineId => 2]);

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and(onHandAt($product, $this->sub->warehouse->lot_stock_location_id))->toBe(5.0)
        ->and(onHandAt($product, transitLocationId()))->toBe(0.0);
});

it('ships a partial quantity and leaves the rest unreserved at the source', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey());
    stockAtBranch($product, $this->main, 10);

    $transfer = $this->service->create(stockTransferData($product, $this->main, $this->sub));
    $transfer = $this->service->confirm($transfer);

    $lineId = $transfer->lines()->firstOrFail()->getKey();

    $transfer = $this->service->ship($transfer, null, [$lineId => 3]);

    expect($transfer->status)->toBe(TransferStatus::InTransit)
        ->and((float) $transfer->lines()->firstOrFail()->quantity_shipped)->toBe(3.0)
        ->and((float) $transfer->lines()->firstOrFail()->cost_price_unit)->toBe(100.0)
        ->and(onHandAt($product, $this->main->warehouse->lot_stock_location_id))->toBe(7.0)
        ->and(onHandAt($product, transitLocationId()))->toBe(3.0)
        ->and(reservedAtBranch($product, $this->main))->toBe(0.0);

    $transfer = $this->service->receive($transfer, null, [$lineId => 3]);

    // Only 3 of the 5 ordered units ever shipped, so the transfer
    // stays partially received — the 2 unshipped units remain open.
    expect($transfer->status)->toBe(TransferStatus::PartiallyReceived)
        ->and(onHandAt($product, $this->sub->warehouse->lot_stock_location_id))->toBe(3.0)
        ->and(onHandAt($product, transitLocationId()))->toBe(0.0);
});

it('unwinds stock at the stored price and cost on return', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey());
    stockAtBranch($product, $this->main, 10);

    $transfer = $this->service->create(stockTransferData($product, $this->main, $this->sub, [
        'lines' => [['product_id' => $product->getKey(), 'quantity' => 5, 'transfer_price_unit' => 150]],
    ]));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);
    $transfer = $this->service->receive($transfer);

    expect(onHandAt($product, $this->sub->warehouse->lot_stock_location_id))->toBe(5.0);

    $transfer = $this->service->return($transfer);

    expect($transfer->status)->toBe(TransferStatus::Returned);

    $returnTransfer = BranchTransferSale::query()
        ->where('parent_transfer_id', $transfer->getKey())
        ->firstOrFail();

    expect($returnTransfer->type)->toBe(TransferType::Return)
        ->and($returnTransfer->operation_id)->toBeNull();

    $returnTransfer = $this->service->confirm($returnTransfer);

    expect($returnTransfer->operation_id)->toBeNull();

    $returnTransfer = $this->service->ship($returnTransfer);

    expect($returnTransfer->status)->toBe(TransferStatus::InTransit)
        ->and($returnTransfer->operation_id)->not->toBeNull()
        ->and((float) $returnTransfer->lines()->firstOrFail()->cost_price_unit)->toBe(150.0)
        ->and(onHandAt($product, $this->sub->warehouse->lot_stock_location_id))->toBe(0.0)
        ->and(onHandAt($product, transitLocationId()))->toBe(5.0);

    $returnTransfer = $this->service->receive($returnTransfer);

    expect($returnTransfer->status)->toBe(TransferStatus::Received)
        ->and(onHandAt($product, $this->main->warehouse->lot_stock_location_id))->toBe(10.0)
        ->and(onHandAt($product, $this->sub->warehouse->lot_stock_location_id))->toBe(0.0)
        ->and(onHandAt($product, transitLocationId()))->toBe(0.0)
        // Phase 4: the return posts an in_refund + COGS reversal at
        // ship and an out_refund at receive. The parent's receivable
        // and payable both fall to zero, and the Main stock layer
        // comes back at the ORIGINAL cost (100), not at P (150).
        ->and($returnTransfer->in_invoice_id)->not->toBeNull()
        ->and($returnTransfer->out_invoice_id)->not->toBeNull()
        ->and($returnTransfer->cogs_move_id)->not->toBeNull();

    $parent = $transfer->refresh();

    expect((float) $parent->outInvoice->amount_residual)->toBe(0.0)
        ->and((float) $parent->inInvoice->amount_residual)->toBe(0.0);

    $mainLayer = StockLayer::query()
        ->where('branch_id', $this->main->getKey())
        ->where('product_id', $product->getKey())
        ->where('quantity_remaining', '>', 0)
        ->orderByDesc('id')
        ->firstOrFail();

    expect((float) $mainLayer->unit_cost)->toBe(100.0);
});

it('moves no stock for a service-only transfer', function () {
    $serviceProduct = stockProduct($this->company->id, $this->admin->getKey(), [
        'type'        => ProductType::SERVICE,
        'is_storable' => false,
    ]);

    $serviceBranch = Branch::withoutGlobalScopes()->where('code', 'CNC-01')->firstOrFail();

    $transfer = $this->service->create([
        'source_branch_id'      => $this->main->getKey(),
        'destination_branch_id' => $serviceBranch->getKey(),
        'lines'                 => [
            ['product_id' => $serviceProduct->getKey(), 'quantity' => 2, 'transfer_price_unit' => 500],
        ],
    ]);

    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);
    $transfer = $this->service->receive($transfer);

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and($transfer->operation_id)->toBeNull()
        ->and(DB::table('inventories_operations')->count())->toBe(0)
        ->and(DB::table('stock_layers')->count())->toBe(0);
});

it('rejects a transfer line for a non-storable goods product', function () {
    $product = stockProduct($this->company->id, $this->admin->getKey(), [
        'is_storable' => false,
    ]);

    $this->service->create(stockTransferData($product, $this->main, $this->sub));
})->throws(ValidationException::class);
