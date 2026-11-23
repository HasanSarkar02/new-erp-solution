<?php

namespace Webkul\Branch\Policies;

use Illuminate\Support\Facades\DB;
use Webkul\Branch\Models\BranchStockWriteOff;
use Webkul\Security\Models\User;

/**
 * Stock write-off authorization (addendum A3, Phase 5).
 *
 * The destination branch that received the goods raises the
 * write-off; only a Main / head-office user may approve, post or
 * forgive it (forgiveness falls Main's receivable, so it is Main's
 * call alone).
 */
class BranchStockWriteOffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_stock_write_off');
    }

    public function view(User $user, BranchStockWriteOff $writeOff): bool
    {
        if (! $user->can('view_stock_write_off')) {
            return false;
        }

        if ($user->can('view_all_branches')) {
            return true;
        }

        return DB::table('branch_user')
            ->where('branch_id', $writeOff->branch_id)
            ->where('user_id', $user->getKey())
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('create_stock_write_off');
    }

    public function approve(User $user, BranchStockWriteOff $writeOff): bool
    {
        return $user->can('approve_stock_write_off') && $user->can('view_all_branches');
    }

    public function post(User $user, BranchStockWriteOff $writeOff): bool
    {
        return $user->can('post_stock_write_off') && $user->can('view_all_branches');
    }

    public function forgive(User $user, BranchStockWriteOff $writeOff): bool
    {
        return $user->can('forgive_stock_write_off') && $user->can('view_all_branches');
    }
}
