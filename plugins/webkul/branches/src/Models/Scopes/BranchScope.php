<?php

namespace Webkul\Branch\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Branch visibility scope (Module 1, plan §7.4).
 *
 * A user who can `view_all_branches` (Main / head-office role) sees every
 * branch. Any other user sees only the branches they are assigned to via
 * the `branch_user` pivot. No-ops in the console and when nobody is
 * authenticated, mirroring CompanyScope.
 */
class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        $user = Auth::user();

        if (! $user) {
            return;
        }

        if ($user->can('view_all_branches')) {
            return;
        }

        $branchIds = DB::table('branch_user')
            ->where('user_id', $user->getKey())
            ->pluck('branch_id');

        $builder->whereIn($model->getTable().'.id', $branchIds);
    }
}
