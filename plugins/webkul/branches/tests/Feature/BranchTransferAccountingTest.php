<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Models\Payment;
use Webkul\Branch\Database\Seeders\DatabaseSeeder;
use Webkul\Branch\Database\Seeders\InternalAccountsSeeder;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Models\StockLayer;
use Webkul\Branch\Services\BranchBalanceService;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Branch\Services\InterBranchAccountingService;
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
        'return_transfer', 'record_inter_branch_payment',
        'view_all_branches', 'view_any_branch_transfer_sale',
    ]);

    Auth::login($this->admin);

    $this->company = Company::query()->firstOrFail();
    $this->main = Branch::withoutGlobalScopes()->where('type', 'main')->firstOrFail();
    $this->sub = Branch::withoutGlobalScopes()->where('code', 'DHK-01')->firstOrFail();
    $this->service = app(BranchTransferService::class);
    $this->accounting = app(InterBranchAccountingService::class);
    $this->balances = app(BranchBalanceService::class);
});

function acctProduct(int $companyId, int $adminId, array $overrides = []): Product
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

function acctTransferData(Product $product, Branch $main, Branch $sub, array $overrides = []): array
{
    return array_merge([
        'source_branch_id'      => $main->getKey(),
        'destination_branch_id' => $sub->getKey(),
        'lines'                 => [
            ['product_id' => $product->getKey(), 'quantity' => 5],
        ],
    ], $overrides);
}

/** On-hand stock for a product at a branch warehouse (no layers — backfilled at cost on confirm). */
function acctStockAt(Product $product, Branch $branch, float $qty): void
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
function acctShippedReceivedTransfer($test, Product $product, float $qty = 5): BranchTransferSale
{
    acctStockAt($product, $test->main, 10);

    $transfer = $test->service->create(acctTransferData($product, $test->main, $test->sub, [
        'lines' => [['product_id' => $product->getKey(), 'quantity' => $qty, 'transfer_price_unit' => 150]],
    ]));
    $transfer = $test->service->confirm($transfer);
    $transfer = $test->service->ship($transfer);
    $transfer = $test->service->receive($transfer);

    return $transfer->refresh();
}

function acctAccountId(string $code): int
{
    return (int) DB::table('accounts_accounts')->where('code', $code)->value('id');
}

/** Lines of a Move keyed by account code: [code => ['debit'=>, 'credit'=>, 'balance'=>, 'amount_residual'=>]]. */
function acctMoveLines(int $moveId): array
{
    return DB::table('accounts_account_move_lines as l')
        ->join('accounts_accounts as a', 'a.id', '=', 'l.account_id')
        ->where('l.move_id', $moveId)
        ->select('a.code', 'l.debit', 'l.credit', 'l.balance', 'l.amount_residual', 'l.display_type', 'l.account_id')
        ->get()
        ->keyBy('code')
        ->map(fn ($row) => (array) $row)
        ->all();
}

function acctMove(int $moveId): array
{
    return (array) DB::table('accounts_account_moves')->where('id', $moveId)->first();
}

function acctAssertBalanced(int $moveId): void
{
    $totals = DB::table('accounts_account_move_lines')
        ->where('move_id', $moveId)
        ->selectRaw('SUM(debit) as debit, SUM(credit) as credit')
        ->first();

    expect(abs((float) $totals->debit - (float) $totals->credit))->toBeLessThan(0.0001);
}

function acctCheckBalances(): int
{
    return Artisan::call('branches:check-balances');
}

it('seeds the internal accounts and journals idempotently', function () {
    $beforeAccounts = DB::table('accounts_accounts')->count();
    $beforeJournals = DB::table('accounts_journals')->count();

    app(InternalAccountsSeeder::class)->run();

    expect(DB::table('accounts_accounts')->count())->toBe($beforeAccounts)
        ->and(DB::table('accounts_journals')->count())->toBe($beforeJournals);

    foreach (['121500', '211500', '400100', '500100', '110150'] as $code) {
        expect(DB::table('accounts_accounts')->where('code', $code)->exists())->toBeTrue();
    }

    foreach (['ITR', 'ITRS', 'ITRP'] as $code) {
        expect(DB::table('accounts_journals')->where('code', $code)->exists())->toBeTrue();
    }

    // Every branch partner points at the internal accounts.
    foreach ([$this->main, $this->sub] as $branch) {
        $property = DB::table('partners_partner_company_properties')
            ->where('partner_id', $branch->partner_id)
            ->where('company_id', $this->company->id)
            ->first();

        expect((int) $property->property_account_receivable_id)->toBe(acctAccountId('121500'))
            ->and((int) $property->property_account_payable_id)->toBe(acctAccountId('211500'));
    }
});

it('posts the mirrored invoices and COGS at ship', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    acctStockAt($product, $this->main, 10);

    $transfer = $this->service->create(acctTransferData($product, $this->main, $this->sub, [
        'lines' => [['product_id' => $product->getKey(), 'quantity' => 5, 'transfer_price_unit' => 150]],
    ]));
    $transfer = $this->service->confirm($transfer);

    // Confirm posts nothing.
    expect($transfer->out_invoice_id)->toBeNull()
        ->and(DB::table('accounts_account_moves')->count())->toBe(0);

    $transfer = $this->service->ship($transfer);

    // Entry 1: out_invoice at P=750 — Dr 121500 / Cr 400100.
    $out = acctMove($transfer->out_invoice_id);

    expect($out['state'])->toBe('posted')
        ->and($out['move_type'])->toBe('out_invoice')
        ->and((float) $out['amount_total'])->toBe(750.0)
        ->and((float) $out['amount_residual'])->toBe(750.0)
        ->and($out['is_internal'])->toBe(1)
        ->and((int) $out['branch_id'])->toBe($this->main->getKey())
        ->and((int) $out['branch_transfer_sale_id'])->toBe($transfer->getKey());

    $outJournal = DB::table('accounts_journals')->where('id', $out['journal_id'])->value('code');

    expect($outJournal)->toBe('ITRS');

    $outLines = acctMoveLines($transfer->out_invoice_id);

    expect((float) $outLines['121500']['debit'])->toBe(750.0)
        ->and((float) $outLines['121500']['credit'])->toBe(0.0)
        ->and((float) $outLines['400100']['credit'])->toBe(750.0)
        ->and((float) $outLines['400100']['debit'])->toBe(0.0);

    acctAssertBalanced($transfer->out_invoice_id);

    // Entry 2: COGS at C=500 — Dr 500100 / Cr 110100.
    $cogs = acctMove($transfer->cogs_move_id);

    expect($cogs['state'])->toBe('posted')
        ->and($cogs['move_type'])->toBe('entry')
        ->and((int) $cogs['branch_id'])->toBe($this->main->getKey());

    $cogsJournal = DB::table('accounts_journals')->where('id', $cogs['journal_id'])->value('code');

    expect($cogsJournal)->toBe('ITR');

    $cogsLines = acctMoveLines($transfer->cogs_move_id);

    expect((float) $cogsLines['500100']['debit'])->toBe(500.0)
        ->and((float) $cogsLines['110100']['credit'])->toBe(500.0);

    acctAssertBalanced($transfer->cogs_move_id);

    // No external accounts or journals touched.
    expect(DB::table('accounts_account_move_lines as l')
        ->join('accounts_accounts as a', 'a.id', '=', 'l.account_id')
        ->whereIn('a.code', ['121000', '211000'])
        ->count())->toBe(0);

    expect($transfer->refresh()->payment_state)->toBe('not_paid')
        ->and((float) $transfer->amount_residual)->toBe(750.0);
});

it('posts the payable invoice at receive and the mirror holds', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    // Entry 3: in_invoice at P=750 — Dr 110100 / Cr 211500.
    $in = acctMove($transfer->in_invoice_id);

    expect($in['state'])->toBe('posted')
        ->and($in['move_type'])->toBe('in_invoice')
        ->and((float) $in['amount_total'])->toBe(750.0)
        ->and((float) $in['amount_residual'])->toBe(750.0)
        ->and((int) $in['branch_id'])->toBe($this->sub->getKey());

    $inJournal = DB::table('accounts_journals')->where('id', $in['journal_id'])->value('code');

    expect($inJournal)->toBe('ITRP');

    $inLines = acctMoveLines($transfer->in_invoice_id);

    expect((float) $inLines['110100']['debit'])->toBe(750.0)
        ->and((float) $inLines['211500']['credit'])->toBe(750.0);

    acctAssertBalanced($transfer->in_invoice_id);

    // Mirror: Main receivable == Sub payable, via the service and the command.
    expect($this->balances->receivableOutstanding($this->sub))->toBe(750.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(750.0)
        ->and($this->balances->netOutstanding($this->sub))->toBe(0.0)
        ->and(acctCheckBalances())->toBe(0);
});

it('posts a partial receipt invoice for the received quantity only', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    acctStockAt($product, $this->main, 10);

    $transfer = $this->service->create(acctTransferData($product, $this->main, $this->sub, [
        'lines' => [['product_id' => $product->getKey(), 'quantity' => 5, 'transfer_price_unit' => 150]],
    ]));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);

    $lineId = $transfer->lines->first()->getKey();

    $transfer = $this->service->receive($transfer, null, [$lineId => 3]);

    expect($transfer->status)->toBe(TransferStatus::PartiallyReceived);

    $in = acctMove($transfer->in_invoice_id);

    expect((float) $in['amount_total'])->toBe(450.0)
        ->and((float) $in['amount_residual'])->toBe(450.0);

    // Receivable 750 (shipped) vs payable 450 (received): the 300
    // gap is the 2 units still in transit — check-balances allows it.
    expect($this->balances->receivableOutstanding($this->sub))->toBe(750.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(450.0)
        ->and(acctCheckBalances())->toBe(0);

    // Receiving the rest posts a second in_invoice for 300.
    $transfer = $this->service->receive($transfer, null, [$lineId => 2]);

    expect($transfer->status)->toBe(TransferStatus::Received)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(750.0)
        ->and(acctCheckBalances())->toBe(0);
});

it('records a partial payment and leaves the correct residual and payment_state', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    $payment = $this->accounting->recordPayment(
        $this->sub,
        $this->main,
        300,
        now()->toDateString(),
        ['allocations' => [$transfer->getKey() => 300]],
    );

    expect($payment->amount)->toBe('300.0000')
        ->and($payment->allocations()->count())->toBe(1)
        ->and((float) $payment->allocations()->first()->amount)->toBe(300.0);

    // Two linked legs, paired both ways.
    $subLeg = Payment::query()->findOrFail($payment->sub_payment_id);
    $mainLeg = Payment::query()->findOrFail($payment->main_payment_id);

    expect((int) $subLeg->paired_internal_transfer_payment_id)->toBe($mainLeg->getKey())
        ->and((int) $mainLeg->paired_internal_transfer_payment_id)->toBe($subLeg->getKey())
        ->and($subLeg->state)->toBe(PaymentStatus::PAID)
        ->and($mainLeg->state)->toBe(PaymentStatus::PAID);

    // Sub leg: Dr 211500 300 / Cr Bank 300. Main leg: Dr Bank 300 / Cr 121500 300.
    $subLines = acctMoveLines($subLeg->move_id);

    expect((float) $subLines['211500']['debit'])->toBe(300.0)
        ->and((float) $subLines['101401']['credit'])->toBe(300.0);

    $mainLines = acctMoveLines($mainLeg->move_id);

    expect((float) $mainLines['101401']['debit'])->toBe(300.0)
        ->and((float) $mainLines['121500']['credit'])->toBe(300.0);

    acctAssertBalanced($subLeg->move_id);
    acctAssertBalanced($mainLeg->move_id);

    // Residuals fall by 300 on both sides; transfer is partial.
    $transfer = $transfer->refresh();

    expect((float) $transfer->amount_paid)->toBe(300.0)
        ->and((float) $transfer->amount_residual)->toBe(450.0)
        ->and($transfer->payment_state)->toBe('partial');

    expect($this->balances->receivableOutstanding($this->sub))->toBe(450.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(450.0)
        ->and($this->balances->totalPaid($this->sub))->toBe(300.0)
        ->and(acctCheckBalances())->toBe(0);
});

it('records a full payment and marks the transfer paid', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    $payment = $this->accounting->recordPayment(
        $this->sub,
        $this->main,
        750,
        now()->toDateString(),
        ['allocations' => [$transfer->getKey() => 750]],
    );

    $transfer = $transfer->refresh();

    expect((float) $transfer->amount_paid)->toBe(750.0)
        ->and((float) $transfer->amount_residual)->toBe(0.0)
        ->and($transfer->payment_state)->toBe('paid');

    expect((float) $transfer->outInvoice->amount_residual)->toBe(0.0)
        ->and((float) $transfer->inInvoice->amount_residual)->toBe(0.0);

    expect($this->balances->receivableOutstanding($this->sub))->toBe(0.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(0.0)
        ->and(acctCheckBalances())->toBe(0);

    $subLeg = Payment::query()->findOrFail($payment->sub_payment_id);

    expect($subLeg->is_reconciled)->toBeTruthy();
});

it('rejects overpayment and over-allocation', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    // More than the outstanding 750.
    $this->accounting->recordPayment(
        $this->sub,
        $this->main,
        751,
        now()->toDateString(),
        ['allocations' => [$transfer->getKey() => 751]],
    );
})->throws(InvalidArgumentException::class);

it('rejects allocations that do not sum to the payment amount', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    $this->accounting->recordPayment(
        $this->sub,
        $this->main,
        300,
        now()->toDateString(),
        ['allocations' => [$transfer->getKey() => 200]],
    );
})->throws(InvalidArgumentException::class);

it('rejects a payment for a transfer that has not shipped yet', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    acctStockAt($product, $this->main, 10);

    $transfer = $this->service->create(acctTransferData($product, $this->main, $this->sub));
    $transfer = $this->service->confirm($transfer);

    $this->accounting->recordPayment(
        $this->sub,
        $this->main,
        100,
        now()->toDateString(),
        ['allocations' => [$transfer->getKey() => 100]],
    );
})->throws(InvalidArgumentException::class);

it('reverses everything to zero when a shipped transfer is cancelled', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    acctStockAt($product, $this->main, 10);

    $transfer = $this->service->create(acctTransferData($product, $this->main, $this->sub, [
        'lines' => [['product_id' => $product->getKey(), 'quantity' => 5, 'transfer_price_unit' => 150]],
    ]));
    $transfer = $this->service->confirm($transfer);
    $transfer = $this->service->ship($transfer);

    expect((float) $transfer->outInvoice->amount_residual)->toBe(750.0)
        ->and((float) ProductQuantity::query()
            ->where('product_id', $product->getKey())
            ->where('location_id', $this->main->warehouse->lot_stock_location_id)
            ->sum('quantity'))->toBe(5.0);

    $transfer = $this->service->cancel($transfer);

    expect($transfer->status)->toBe(TransferStatus::Cancelled)
        ->and((float) $transfer->outInvoice->fresh()->amount_residual)->toBe(0.0);

    // COGS reversal nets the stock account to zero for this transfer.
    $stockBalance = (float) DB::table('accounts_account_move_lines as l')
        ->join('accounts_accounts as a', 'a.id', '=', 'l.account_id')
        ->join('accounts_account_moves as m', 'm.id', '=', 'l.move_id')
        ->where('m.branch_transfer_sale_id', $transfer->getKey())
        ->where('a.code', '110100')
        ->sum('l.balance');

    expect($stockBalance)->toBe(0.0);

    // Stock is back at Main, layers restored.
    expect((float) ProductQuantity::query()
        ->where('product_id', $product->getKey())
        ->where('location_id', $this->main->warehouse->lot_stock_location_id)
        ->sum('quantity'))->toBe(10.0);

    expect((float) StockLayer::query()
        ->where('branch_id', $this->main->getKey())
        ->where('product_id', $product->getKey())
        ->sum('quantity_remaining'))->toBe(10.0);

    expect($this->balances->receivableOutstanding($this->sub))->toBe(0.0)
        ->and(acctCheckBalances())->toBe(0);
});

it('refuses to cancel a shipped transfer that already has a payment', function () {
    $product2 = acctProduct($this->company->id, $this->admin->getKey());
    acctStockAt($product2, $this->main, 10);

    $transfer2 = $this->service->create(acctTransferData($product2, $this->main, $this->sub, [
        'lines' => [['product_id' => $product2->getKey(), 'quantity' => 5, 'transfer_price_unit' => 150]],
    ]));
    $transfer2 = $this->service->confirm($transfer2);
    $transfer2 = $this->service->ship($transfer2);

    // Pay part of it, then try to cancel while it is still InTransit.
    $this->accounting->recordPayment(
        $this->sub,
        $this->main,
        100,
        now()->toDateString(),
        ['allocations' => [$transfer2->getKey() => 100]],
    );

    $this->service->cancel($transfer2->refresh());
})->throws(ValidationException::class);

it('unwinds the receivable and payable on a full return', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    $transfer = $this->service->return($transfer);

    $returnTransfer = BranchTransferSale::query()
        ->where('parent_transfer_id', $transfer->getKey())
        ->firstOrFail();

    $returnTransfer = $this->service->confirm($returnTransfer);
    $returnTransfer = $this->service->ship($returnTransfer);

    // Return ship: Sub in_refund Dr 211500 750 / Cr 110100 750,
    // Main COGS reversal Dr 110100 500 / Cr 500100 500.
    $inRefund = acctMove($returnTransfer->in_invoice_id);

    expect($inRefund['move_type'])->toBe('in_refund')
        ->and($inRefund['state'])->toBe('posted');

    $refundLines = acctMoveLines($returnTransfer->in_invoice_id);

    expect((float) $refundLines['211500']['debit'])->toBe(750.0)
        ->and((float) $refundLines['110100']['credit'])->toBe(750.0);

    acctAssertBalanced($returnTransfer->in_invoice_id);

    $cogsReversal = acctMove($returnTransfer->cogs_move_id);
    $cogsReversalLines = acctMoveLines($returnTransfer->cogs_move_id);

    expect((float) $cogsReversalLines['110100']['debit'])->toBe(500.0)
        ->and((float) $cogsReversalLines['500100']['credit'])->toBe(500.0);

    acctAssertBalanced($returnTransfer->cogs_move_id);

    // The Sub payable is already zero after the in_refund.
    expect($this->balances->payableOutstanding($this->sub))->toBe(0.0);

    $returnTransfer = $this->service->receive($returnTransfer);

    // Return receive: Main out_refund Dr 400100 750 / Cr 121500 750.
    $outRefund = acctMove($returnTransfer->out_invoice_id);

    expect($outRefund['move_type'])->toBe('out_refund')
        ->and($outRefund['state'])->toBe('posted');

    $outRefundLines = acctMoveLines($returnTransfer->out_invoice_id);

    expect((float) $outRefundLines['400100']['debit'])->toBe(750.0)
        ->and((float) $outRefundLines['121500']['credit'])->toBe(750.0);

    acctAssertBalanced($returnTransfer->out_invoice_id);

    // Parent: receivable and payable both zero; mirror holds.
    $parent = $transfer->refresh();

    expect((float) $parent->outInvoice->amount_residual)->toBe(0.0)
        ->and((float) $parent->inInvoice->amount_residual)->toBe(0.0)
        ->and($this->balances->receivableOutstanding($this->sub))->toBe(0.0)
        ->and(acctCheckBalances())->toBe(0);

    // Main stock layer is back at the original cost C=100.
    $mainLayer = StockLayer::query()
        ->where('branch_id', $this->main->getKey())
        ->where('product_id', $product->getKey())
        ->where('quantity_remaining', '>', 0)
        ->orderByDesc('id')
        ->firstOrFail();

    expect((float) $mainLayer->unit_cost)->toBe(100.0);
});

it('posts mirrored invoices without COGS for a service transfer', function () {
    $serviceProduct = acctProduct($this->company->id, $this->admin->getKey(), [
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
        ->and($transfer->out_invoice_id)->not->toBeNull()
        ->and($transfer->in_invoice_id)->not->toBeNull()
        ->and($transfer->cogs_move_id)->toBeNull();

    $out = acctMove($transfer->out_invoice_id);

    expect((float) $out['amount_total'])->toBe(1000.0);

    acctAssertBalanced($transfer->out_invoice_id);
    acctAssertBalanced($transfer->in_invoice_id);

    expect(acctCheckBalances())->toBe(0);
});

it('flags a broken mirror in check-balances', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    expect(acctCheckBalances())->toBe(0);

    // Break the mirror directly in the ledger: understate the payable.
    DB::table('accounts_account_moves')
        ->where('id', $transfer->in_invoice_id)
        ->update(['amount_residual' => -100]);

    expect(acctCheckBalances())->toBe(1);
});

it('loads the branch view page with the balance section', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    $this->actingAs($this->admin)
        ->get('/admin/branches/'.$this->sub->getKey())
        ->assertOk()
        ->assertSee('Branch balances')
        ->assertSee('Receivable outstanding')
        ->assertSee('Payable outstanding');
});

it('shows the record payment action on the transfer view page', function () {
    $product = acctProduct($this->company->id, $this->admin->getKey());
    $transfer = acctShippedReceivedTransfer($this, $product);

    $this->actingAs($this->admin)
        ->get('/admin/branch-transfer-sales/'.$transfer->getKey())
        ->assertOk()
        ->assertSee('Record payment');
});

it('splits one payment across two transfers', function () {
    $productA = acctProduct($this->company->id, $this->admin->getKey());
    $transferA = acctShippedReceivedTransfer($this, $productA);

    $productB = acctProduct($this->company->id, $this->admin->getKey());
    $transferB = acctShippedReceivedTransfer($this, $productB);

    $payment = $this->accounting->recordPayment(
        $this->sub,
        $this->main,
        1000,
        now()->toDateString(),
        ['allocations' => [
            $transferA->getKey() => 750,
            $transferB->getKey() => 250,
        ]],
    );

    expect($payment->allocations()->count())->toBe(2)
        ->and((float) $transferA->refresh()->amount_residual)->toBe(0.0)
        ->and($transferA->payment_state)->toBe('paid')
        ->and((float) $transferB->refresh()->amount_residual)->toBe(500.0)
        ->and($transferB->payment_state)->toBe('partial')
        ->and($this->balances->receivableOutstanding($this->sub))->toBe(500.0)
        ->and($this->balances->payableOutstanding($this->sub))->toBe(500.0)
        ->and(acctCheckBalances())->toBe(0);

    $subLeg = Payment::query()->findOrFail($payment->sub_payment_id);

    expect((float) $subLeg->amount)->toBe(1000.0);
});
