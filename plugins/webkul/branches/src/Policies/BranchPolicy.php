<?php

namespace Webkul\Branch\Policies;

use Illuminate\Support\Facades\DB;
use Webkul\Branch\Models\Branch;
use Webkul\Security\Models\User;

/**
 * Branch authorization (plan §7.4).
 *
 * `view_all_branches` is the Main / head-office capability. Every other
 * user is limited to the branches they are assigned to in `branch_user`.
 */
class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_branch');
    }

    public function view(User $user, Branch $branch): bool
    {
        if (! $user->can('view_branch')) {
            return false;
        }

        if ($user->can('view_all_branches')) {
            return true;
        }

        return $this->isAssigned($user, $branch);
    }

    public function create(User $user): bool
    {
        return $user->can('create_branch');
    }

    public function update(User $user, Branch $branch): bool
    {
        if (! $user->can('edit_branch')) {
            return false;
        }

        return $user->can('view_all_branches') || $this->isAssigned($user, $branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->can('delete_branch') && $user->can('view_all_branches');
    }

    public function viewProfile(User $user, Branch $branch): bool
    {
        return $user->can('view_branch_profile')
            && ($user->can('view_all_branches') || $this->isAssigned($user, $branch));
    }

    protected function isAssigned(User $user, Branch $branch): bool
    {
        return DB::table('branch_user')
            ->where('branch_id', $branch->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
