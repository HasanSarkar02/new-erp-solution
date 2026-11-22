<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Account\Models\Move;
use Webkul\Branch\Database\Seeders\DatabaseSeeder;
use Webkul\Branch\Database\Seeders\DemoTransfersSeeder;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Enums\WriteOffStatus;
use Webkul\Branch\Filament\Widgets\BranchesOweUsWidget;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Models\StockLayer;
use Webkul\Branch\Services\BranchBalanceService;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Branch\Services\BranchWriteOffService;
use Webkul\Branch\Services\InterBranchAccountingService;
use Webkul\Branch\Services\TransferStockService;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Models\Scrap;
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
        'view_branch_profile', 'view_all_branches',
        'view_transfer', 'create_transfer', 'edit_transfer', 'delete_transfer',
        'confirm_transfer', 'ship_transfer', 'receive_transfer', 'cancel_transfer',
        'return_transfer', 'close_short_transfer', 'report_lost_stock',
        'record_inter_branch_payment', 'view_any_branch_transfer_sale',
        'view_stock_write_off', 'create_stock_write_off', 'approve_stock_write_off',
        'post_stock_write_off', 'forgive_stock_write_off', 'view_any_branch_stock_write_off',
    ]);

    Auth::login($this->admin);

    $this->company = Company::query()->firstOrFail();
    $this->main = Branch::withoutGlobalScopes()->where('type', 'main')->firstOrFail();
    $this->sub = Branch::withoutGlobalScopes()->where('code', 'DHK-01')->firstOrFail();
    $this->service = app(BranchTransferService::class);
    $this->accounting = app(InterBranchAccountingService::class);
    $this->balances = app(BranchBalanceService::class);
    $this->writeOffs = app(BranchWriteOffService::class);
});

function p5Product(int $companyId, int $adminId, array $overrides = []): Product
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

/** On-hand stock for a product at a branch warehouse (no layers — backfilled at cost on confirm). */
function p5StockAt(Product $product, Branch $branch, float $qty): void
{
    ProductQuantity::query()->create([
        'product_id'  => $product->getKey(),
        'location_id' => $branch->warehouse->lot_stock_location_id,
        'quantity'    => $qty,
        'incoming_at' => now(),
        'company_id'  => $product->company_id,
    ]);
}

/** Full flow: create → confirm → ship → receive. P=150, C=100, 5 units → 750 / 500. */
function p5ReceivedTransfer($test, Product $product, float $qty = 5): BranchTransferSale
{
    p5StockAt($product, $test->main, 10);

    $transfer = $test->service->create([
        'source_branch_id'      => $test->main->getKey(),
        'destination_branch_id' => $test->sub->getKey(),
        'lines'                 => [['product_id' => $product->getKey(), 'quantity' => $qty, 'transfer_price_unit' => 150]],
    ]);
    $transfer = $test->service->confirm($transfer);
    $transfer = $test->service->ship($transfer);
    $transfer = $test->service->receive($transfer);

    return $transfer->refresh();
}

function p5MoveLineSum(string $accountCode, ?int $branchId = null): float
{
    $query = DB::table('accounts_account_move_lines')
        ->join('accounts_accounts', 'accounts_account_move_lines.account_id', '=', 'accounts_accounts.id')
        ->where('accounts_accounts.code', $accountCode)
        ->where('accounts_account_move_lines.is_internal', true);

    if ($branchId !== null) {
        $query->where('accounts_account_move_lines.branch_id', $branchId);
    }

    return (float) $query->sum(DB::raw('accounts_account_move_lines.debit - accounts_account_move_lines.credit'));
}

it('profile balances match invoice residuals after a partial payment', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    $transfer = p5ReceivedTransfer($this, $product);

    $this->accounting->recordPayment($this->sub, $this->main, 300, now()->toDateString(), [
        'allocations' => [$transfer->getKey() => 300],
    ]);

    expect($this->balances->receivableOutstanding($this->sub))->toBe(450.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(450.0)
        ->and($this->balances->receivableOutstanding($this->main))->toBe(450.0)
        ->and($this->balances->netOutstanding($this->sub))->toBe(0.0)
        ->and($this->balances->overdueReceivable($this->sub))->toBe(0.0)
        ->and($transfer->refresh()->payment_state)->toBe('partial');
});

it('close short closes the unshipped remainder and the transfer can complete', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    p5StockAt($product, $this->main, 10);

    $transfer = $this->service->create([
        'source_branch_id'      => $this->main->getKey(),
        'destination_branch_id' => $this->sub->getKey(),
        'lines'                 => [['product_id' => $product->getKey(), 'quantity' => 10, 'transfer_price_unit' => 150]],
    ]);
    $transfer = $this->service->confirm($transfer);

    $lineId = $transfer->lines->first()->getKey();
    $transfer = $this->service->ship($transfer, null, [$lineId => 8]);
    $transfer = $this->service->receive($transfer, null, [$lineId => 8]);

    expect($transfer->status)->toBe(TransferStatus::PartiallyReceived);

    $movesBefore = Move::query()->where('is_internal', true)->count();

    $transfer = $this->service->closeShort($transfer, null, 'Supplier short-shipped 2 units.');

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and($transfer->is_closed_short)->toBeTrue()
        ->and($transfer->closed_short_at)->not->toBeNull()
        ->and($transfer->histories()->where('note', 'like', '%Closed short%')->exists())->toBeTrue()
        // No stock or accounting effect for the unshipped part.
        ->and(Move::query()->where('is_internal', true)->count())->toBe($movesBefore)
        ->and((float) StockLayer::query()
            ->where('branch_id', $this->main->getKey())
            ->where('product_id', $product->getKey())
            ->sum('quantity_remaining'))->toBe(2.0);
});

it('close short refuses when nothing is unshipped', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    $transfer = p5ReceivedTransfer($this, $product);

    expect(fn () => $this->service->closeShort($transfer))->toThrow(ValidationException::class);
});

it('damaged return credits only the agreed value and scraps the goods', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    $transfer = p5ReceivedTransfer($this, $product);

    $lineId = $transfer->lines->first()->getKey();
    $this->service->returnDamaged($transfer, null, [$lineId => 2], [$lineId => 50]);

    $return = BranchTransferSale::query()->where('parent_transfer_id', $transfer->getKey())->firstOrFail();

    expect($return->return_reason)->toBe('damaged')
        ->and((float) $return->lines->first()->credit_value_unit)->toBe(50.0);

    $this->service->confirm($return);
    $this->service->ship($return);
    $this->service->receive($return);

    $parent = $transfer->refresh();

    // Payable falls only by the agreed credit: 750 − 100 = 650, both sides.
    expect((float) $parent->inInvoice->amount_residual)->toBe(650.0)
        ->and((float) $parent->outInvoice->amount_residual)->toBe(650.0)
        ->and($this->balances->receivableOutstanding($this->sub))->toBe(650.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(650.0)
        // Damage loss (P − V) × 2 = 200 on the Sub.
        ->and(p5MoveLineSum('644000', $this->sub->getKey()))->toBe(200.0);

    // The goods sit in Main's Scrap location, valued at the credit V=50.
    $scrapLocation = app(TransferStockService::class)->scrapLocation($this->main);

    expect((float) ProductQuantity::query()
        ->where('product_id', $product->getKey())
        ->where('location_id', $scrapLocation->getKey())
        ->sum('quantity'))->toBe(2.0)
        ->and((float) StockLayer::query()
            ->where('branch_id', $this->main->getKey())
            ->where('location_id', $scrapLocation->getKey())
            ->sum('quantity_remaining'))->toBe(2.0)
        ->and((float) StockLayer::query()
            ->where('branch_id', $this->main->getKey())
            ->where('location_id', $scrapLocation->getKey())
            ->value('unit_cost'))->toBe(50.0)
        ->and((float) StockLayer::query()
            ->where('branch_id', $this->main->getKey())
            ->where('location_id', $scrapLocation->getKey())
            ->value('original_cost_unit'))->toBe(100.0);
});

it('damaged return with zero credit gives no refund and the full loss', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    $transfer = p5ReceivedTransfer($this, $product);

    $lineId = $transfer->lines->first()->getKey();
    $this->service->returnDamaged($transfer, null, [$lineId => 2], [$lineId => 0]);

    $return = BranchTransferSale::query()->where('parent_transfer_id', $transfer->getKey())->firstOrFail();

    $this->service->confirm($return);
    $this->service->ship($return);
    $this->service->receive($return);

    // Payable does not fall at all; the whole P is the Sub's loss.
    expect((float) $transfer->refresh()->inInvoice->amount_residual)->toBe(750.0)
        ->and((float) $transfer->refresh()->outInvoice->amount_residual)->toBe(750.0)
        ->and(p5MoveLineSum('644000', $this->sub->getKey()))->toBe(300.0)
        ->and($return->refresh()->in_invoice_id)->toBeNull();
});

it('lost write-off keeps the payable and posts the loss at stock value', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    $transfer = p5ReceivedTransfer($this, $product);

    $writeOff = $this->writeOffs->create(
        $this->sub,
        $transfer,
        [['product_id' => $product->getKey(), 'quantity' => 2]],
        'Two chairs missing after the weekend stock count; CCTV reviewed, no sign of theft.',
        $this->admin,
    );

    expect($writeOff->status)->toBe(WriteOffStatus::Draft)
        ->and((float) $writeOff->amount_total)->toBe(300.0);

    $writeOff = $this->writeOffs->approve($writeOff, $this->admin);
    $writeOff = $this->writeOffs->post($writeOff, $this->admin);

    expect($writeOff->status)->toBe(WriteOffStatus::Posted)
        ->and($writeOff->account_move_id)->not->toBeNull();

    $line = $writeOff->lines()->firstOrFail();
    $scrap = Scrap::query()->findOrFail($line->inventory_scrap_id);

    expect($scrap->state->value)->toBe('done')
        // Sub stock falls by 2 (layers and quants), payable does NOT fall.
        ->and((float) StockLayer::query()
            ->where('branch_id', $this->sub->getKey())
            ->where('product_id', $product->getKey())
            ->sum('quantity_remaining'))->toBe(3.0)
        ->and((float) ProductQuantity::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $this->sub->warehouse->lot_stock_location_id)
            ->sum('quantity'))->toBe(3.0)
        ->and((float) $transfer->refresh()->inInvoice->amount_residual)->toBe(750.0)
        ->and((float) $transfer->refresh()->outInvoice->amount_residual)->toBe(750.0)
        // Loss Dr 645000 300 on the Sub.
        ->and(p5MoveLineSum('645000', $this->sub->getKey()))->toBe(300.0);
});

it('forgiving a write-off falls the payable and the receivable', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    $transfer = p5ReceivedTransfer($this, $product);

    $writeOff = $this->writeOffs->create(
        $this->sub,
        $transfer,
        [['product_id' => $product->getKey(), 'quantity' => 2]],
        'Main accepts the loss as a company loss after investigation.',
        $this->admin,
    );

    $this->writeOffs->approve($writeOff, $this->admin);
    $writeOff = $this->writeOffs->post($writeOff, $this->admin);
    $writeOff = $this->writeOffs->forgive($writeOff, $this->admin);

    expect($writeOff->status)->toBe(WriteOffStatus::Forgiven)
        ->and($writeOff->forgiveness_move_id)->not->toBeNull()
        ->and((float) $transfer->refresh()->inInvoice->amount_residual)->toBe(450.0)
        ->and((float) $transfer->refresh()->outInvoice->amount_residual)->toBe(450.0)
        ->and($this->balances->receivableOutstanding($this->sub))->toBe(450.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(450.0)
        // Main absorbs the loss: Dr 645000 on Main.
        ->and(p5MoveLineSum('645000', $this->main->getKey()))->toBe(300.0);
});

it('write-off refuses more than the branch received', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    $transfer = p5ReceivedTransfer($this, $product);

    expect(fn () => $this->writeOffs->create(
        $this->sub,
        $transfer,
        [['product_id' => $product->getKey(), 'quantity' => 6]],
        'Too many.',
        $this->admin,
    ))->toThrow(ValidationException::class);
});

it('demo seeder is idempotent and covers paid, partial and in-transit states', function () {
    $seeder = new DemoTransfersSeeder;
    $seeder->force = true;
    $seeder->run();
    $seeder->run();

    $uttara = Branch::withoutGlobalScopes()->where('code', 'UTT-01')->firstOrFail();
    $mirpur = Branch::withoutGlobalScopes()->where('code', 'MIR-01')->firstOrFail();
    $service = Branch::withoutGlobalScopes()->where('code', 'SVC-01')->firstOrFail();

    expect($uttara->is_demo)->toBeTrue()
        ->and($service->type->value)->toBe('service')
        ->and(BranchTransferSale::query()->whereIn('reference', ['DEMO-PAID', 'DEMO-PARTIAL', 'DEMO-TRANSIT'])->count())->toBe(3);

    $paid = BranchTransferSale::query()->where('reference', 'DEMO-PAID')->firstOrFail();
    $partial = BranchTransferSale::query()->where('reference', 'DEMO-PARTIAL')->firstOrFail();
    $transit = BranchTransferSale::query()->where('reference', 'DEMO-TRANSIT')->firstOrFail();

    expect($paid->status)->toBe(TransferStatus::Received)
        ->and($paid->payment_state)->toBe('paid')
        ->and($partial->status)->toBe(TransferStatus::Received)
        ->and($partial->payment_state)->toBe('partial')
        ->and((float) $partial->amount_residual)->toBe(30000.0)
        ->and($transit->status)->toBe(TransferStatus::InTransit)
        ->and($this->balances->receivableOutstanding($mirpur))->toBe(30000.0);
});

it('branches owe us widget lists the sub branch balance', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    p5ReceivedTransfer($this, $product);

    Livewire::test(BranchesOweUsWidget::class)
        ->assertSee('DHK-01')
        ->assertSee('750.00');
});

it('a product variant can be transferred but a configurable parent cannot', function () {
    $parent = p5Product($this->company->id, $this->admin->id, [
        'name' => 'Phase5 Sofa', 'is_configurable' => true,
    ]);
    $variant = p5Product($this->company->id, $this->admin->id, [
        'name' => 'Phase5 Sofa, Grey', 'parent_id' => $parent->getKey(),
    ]);

    p5StockAt($variant, $this->main, 10);

    $transfer = $this->service->create([
        'source_branch_id'      => $this->main->getKey(),
        'destination_branch_id' => $this->sub->getKey(),
        'lines'                 => [['product_id' => $variant->getKey(), 'quantity' => 2, 'transfer_price_unit' => 150]],
    ]);

    expect($transfer->lines->first()->product_id)->toBe($variant->getKey());

    expect(fn () => $this->service->create([
        'source_branch_id'      => $this->main->getKey(),
        'destination_branch_id' => $this->sub->getKey(),
        'lines'                 => [['product_id' => $parent->getKey(), 'quantity' => 1]],
    ]))->toThrow(ValidationException::class);
});

it('profile balances net open refunds when a return follows full payment', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    $transfer = p5ReceivedTransfer($this, $product);

    $this->accounting->recordPayment($this->sub, $this->main, 750, now()->toDateString(), [
        'allocations' => [$transfer->getKey() => 750],
    ]);

    $lineId = $transfer->lines->first()->getKey();
    $this->service->return($transfer, null, [$lineId => 1]);

    $return = BranchTransferSale::query()->where('parent_transfer_id', $transfer->getKey())->firstOrFail();
    $this->service->confirm($return);
    $this->service->ship($return);
    $this->service->receive($return);

    // The parent invoices are paid, so the 150 refunds stay open:
    // Main owes the Sub 150 back. Both legs net to -150; the
    // mirror (receivable == payable) still holds.
    expect($this->balances->receivableOutstanding($this->sub))->toBe(-150.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(-150.0)
        ->and($this->balances->netOutstanding($this->sub))->toBe(0.0);
});

it('a payment after partial receipts reconciles every receipt invoice', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    p5StockAt($product, $this->main, 10);

    $transfer = $this->service->create([
        'source_branch_id'      => $this->main->getKey(),
        'destination_branch_id' => $this->sub->getKey(),
        'lines'                 => [['product_id' => $product->getKey(), 'quantity' => 5, 'transfer_price_unit' => 150]],
    ]);
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);

    $lineId = $transfer->lines->first()->getKey();
    $transfer = $this->service->receive($transfer, null, [$lineId => 3]);
    $transfer = $this->service->receive($transfer, null, [$lineId => 2]);

    $inInvoices = Move::query()
        ->where('branch_transfer_sale_id', $transfer->getKey())
        ->where('move_type', 'in_invoice')
        ->orderBy('id')
        ->get();

    expect($inInvoices)->toHaveCount(2);

    $this->accounting->recordPayment($this->sub, $this->main, 750, now()->toDateString(), [
        'allocations' => [$transfer->getKey() => 750],
    ]);

    // Both receipt invoices fully reconciled — the Phase 5 UAT found
    // the second one stuck at 32000 before this fix.
    expect($inInvoices->map(fn (Move $m) => (float) $m->fresh()->amount_residual)->all())->toBe([0.0, 0.0])
        ->and($this->balances->payableOutstanding($this->sub))->toBe(0.0)
        ->and($this->balances->receivableOutstanding($this->sub))->toBe(0.0)
        ->and($transfer->refresh()->payment_state)->toBe('paid');
});

it('a return after partial receipts reconciles across every receipt invoice', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    p5StockAt($product, $this->main, 10);

    $transfer = $this->service->create([
        'source_branch_id'      => $this->main->getKey(),
        'destination_branch_id' => $this->sub->getKey(),
        'lines'                 => [['product_id' => $product->getKey(), 'quantity' => 5, 'transfer_price_unit' => 150]],
    ]);
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);

    $lineId = $transfer->lines->first()->getKey();
    $transfer = $this->service->receive($transfer, null, [$lineId => 3]);
    $transfer = $this->service->receive($transfer, null, [$lineId => 2]);

    // Return 4 units (600) before any payment: 450 against the first
    // receipt invoice, 150 against the second.
    $this->service->return($transfer, null, [$lineId => 4]);

    $return = BranchTransferSale::query()->where('parent_transfer_id', $transfer->getKey())->firstOrFail();
    $this->service->confirm($return);
    $this->service->ship($return);
    $this->service->receive($return);

    $invoices = Move::query()
        ->where('branch_transfer_sale_id', $transfer->getKey())
        ->where('move_type', 'in_invoice')
        ->orderBy('id')
        ->get();

    expect($invoices->map(fn (Move $m) => (float) $m->fresh()->amount_residual)->all())->toBe([0.0, 150.0])
        ->and($this->balances->payableOutstanding($this->sub))->toBe(150.0)
        ->and($this->balances->receivableOutstanding($this->sub))->toBe(150.0);
});

it('branch profile page loads for the sub branch', function () {
    $product = p5Product($this->company->id, $this->admin->id);
    p5ReceivedTransfer($this, $product);

    $this->get('/admin/branches/profile?branch='.$this->sub->getKey())
        ->assertOk()
        ->assertSee('Dhanmondi Showroom')
        ->assertSee('750.00');
});
