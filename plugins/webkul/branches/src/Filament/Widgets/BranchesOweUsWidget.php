<?php

namespace Webkul\Branch\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Services\BranchBalanceService;

/**
 * "Branches owe us" dashboard widget (plan §8.4, Phase 5).
 *
 * One row per Sub branch: what it owes Main (live invoice
 * residuals), what is overdue, and a link to its Branch Profile.
 * Main / head-office users see every Sub branch; a Sub user sees
 * only their own branch.
 */
class BranchesOweUsWidget extends Widget
{
    protected string $view = 'branches::filament.widgets.branches-owe-us';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('view_branch_profile');
    }

    /** @return array<int, array{branch: Branch, receivable: float, overdue: float, payable: float}> */
    public function getRows(): array
    {
        $balances = app(BranchBalanceService::class);

        $branches = Branch::query()
            ->where('type', BranchType::Sub)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        if (! auth()->user()?->can('view_all_branches')) {
            $assigned = DB::table('branch_user')
                ->where('user_id', auth()->id())
                ->pluck('branch_id')
                ->all();

            $branches = $branches->whereIn('id', $assigned);
        }

        return $branches->map(fn (Branch $branch) => [
            'branch'     => $branch,
            'receivable' => $balances->receivableOutstanding($branch),
            'overdue'    => $balances->overdueReceivable($branch),
            'payable'    => $balances->payableOutstanding($branch),
        ])->all();
    }

    public function getTotalReceivable(): float
    {
        return collect($this->getRows())->sum('receivable');
    }

    public function getTotalOverdue(): float
    {
        return collect($this->getRows())->sum('overdue');
    }
}
