<?php

namespace Webkul\Branch\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Webkul\Account\Models\MoveLine;
use Webkul\Branch\Filament\Resources\BranchResource;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchStockWriteOff;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Models\InterBranchPayment;
use Webkul\Branch\Models\StockLayer;
use Webkul\Branch\Services\BranchBalanceService;

/**
 * Branch Profile (plan §8.4, Phase 5).
 *
 * One page per branch: the live balance (read from invoice
 * residuals via BranchBalanceService — never a stored balance),
 * transfer history, payment history, stock summary, write-offs and
 * the ledger lines for the branch, plus an overdue flag. Reached
 * from the Branches list / branch view, or directly with
 * ?branch=<id>. A Sub-branch user sees only their own branch.
 */
class BranchProfile extends Page
{
    protected static ?string $slug = 'branches/profile';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'branches::filament.pages.branch-profile';

    public ?Branch $branch = null;

    public function mount(): void
    {
        $branchId = (int) request()->query('branch');

        $this->branch = Branch::withoutGlobalScopes()->findOrFail($branchId);

        abort_unless(Gate::allows('viewProfile', $this->branch), 403);
    }

    public static function getNavigationLabel(): string
    {
        return 'Branch Profile';
    }

    public function getTitle(): string
    {
        return 'Branch Profile — '.($this->branch?->name ?? '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTransfer')
                ->label('New transfer')
                ->icon('heroicon-o-plus')
                ->url(BranchTransferSaleResource::getUrl('create'))
                ->visible(fn () => Gate::allows('create_transfer')),
            Action::make('viewBranch')
                ->label('Branch record')
                ->icon('heroicon-o-building-office')
                ->url(fn () => $this->branch ? BranchResource::getUrl('view', ['record' => $this->branch]) : '#'),
        ];
    }

    /** @return array<string, float> */
    public function getKpis(): array
    {
        $balances = app(BranchBalanceService::class);

        return [
            'receivable'  => $balances->receivableOutstanding($this->branch),
            'payable'     => $balances->payableOutstanding($this->branch),
            'net'         => $balances->netOutstanding($this->branch),
            'overdue'     => $balances->overdueReceivable($this->branch),
            'transferred' => $balances->totalTransferred($this->branch),
            'paid'        => $balances->totalPaid($this->branch),
        ];
    }

    public function isOverdue(): bool
    {
        return $this->getKpis()['overdue'] > 0;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, BranchTransferSale> */
    public function getTransfers()
    {
        return BranchTransferSale::query()
            ->where(fn ($query) => $query
                ->where('source_branch_id', $this->branch->getKey())
                ->orWhere('destination_branch_id', $this->branch->getKey()))
            ->with(['sourceBranch', 'destinationBranch'])
            ->latest('transfer_date')
            ->limit(30)
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, InterBranchPayment> */
    public function getPayments()
    {
        return InterBranchPayment::query()
            ->where(fn ($query) => $query
                ->where('payer_branch_id', $this->branch->getKey())
                ->orWhere('payee_branch_id', $this->branch->getKey()))
            ->with(['payerBranch', 'payeeBranch', 'allocations'])
            ->latest('payment_date')
            ->limit(20)
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MoveLine> */
    public function getLedgerLines()
    {
        return MoveLine::query()
            ->where('branch_id', $this->branch->getKey())
            ->where('is_internal', true)
            ->with(['account', 'move'])
            ->latest('date')
            ->limit(30)
            ->get();
    }

    /** @return Collection<int, object> */
    public function getStockSummary()
    {
        return StockLayer::query()
            ->where('branch_id', $this->branch->getKey())
            ->where('quantity_remaining', '>', 0)
            ->join('products_products', 'stock_layers.product_id', '=', 'products_products.id')
            ->leftJoin('inventories_locations', 'stock_layers.location_id', '=', 'inventories_locations.id')
            ->select([
                'products_products.name as product_name',
                'inventories_locations.name as location_name',
                DB::raw('SUM(stock_layers.quantity_remaining) as quantity'),
                DB::raw('SUM(stock_layers.quantity_remaining * stock_layers.unit_cost) as value'),
                DB::raw('SUM(stock_layers.quantity_remaining * COALESCE(stock_layers.original_cost_unit, stock_layers.unit_cost)) as cost_value'),
            ])
            ->groupBy('products_products.name', 'inventories_locations.name')
            ->orderBy('products_products.name')
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, BranchStockWriteOff> */
    public function getWriteOffs()
    {
        return BranchStockWriteOff::query()
            ->where('branch_id', $this->branch->getKey())
            ->with(['transfer', 'lines'])
            ->latest()
            ->limit(10)
            ->get();
    }
}
