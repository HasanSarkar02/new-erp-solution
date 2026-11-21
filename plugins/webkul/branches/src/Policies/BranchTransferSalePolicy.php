<?php

namespace Webkul\Branch\Policies;

use Illuminate\Support\Facades\DB;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Security\Models\User;

/**
 * Internal Transfer Sale authorization (plan §7.4).
 *
 * A Main / head-office user (view_all_branches) sees every transfer.
 * A Sub-branch user sees only transfers where their branch is the
 * source or the destination. The document actions (confirm / ship /
 * receive / cancel / return) are separate permissions so Main and Sub
 * responsibilities stay distinct.
 */
class BranchTransferSalePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view_transfer');
    }

    public function view(User $user, BranchTransferSale $transfer): bool
    {
        if (! $user->can('view_transfer')) {
            return false;
        }

        if ($user->can('view_all_branches')) {
            return true;
        }

        return $this->isInvolved($user, $transfer);
    }

    public function create(User $user): bool
    {
        return $user->can('create_transfer');
    }

    public function update(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('edit_transfer')
            && ($user->can('view_all_branches') || $this->isInvolved($user, $transfer));
    }

    public function delete(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('delete_transfer')
            && $user->can('view_all_branches')
            && $transfer->status->isEditable();
    }

    public function confirm(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('confirm_transfer') && $this->canActForSource($user, $transfer);
    }

    public function ship(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('ship_transfer') && $this->canActForSource($user, $transfer);
    }

    public function receive(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('receive_transfer')
            && ($user->can('view_all_branches') || $this->isAssigned($user, (int) $transfer->destination_branch_id));
    }

    public function cancel(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('cancel_transfer') && $this->canActForSource($user, $transfer);
    }

    public function return(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('return_transfer')
            && ($user->can('view_all_branches') || $this->isAssigned($user, (int) $transfer->destination_branch_id));
    }

    public function approve(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('view_all_branches') && $transfer->requiresMainApproval();
    }

    protected function isInvolved(User $user, BranchTransferSale $transfer): bool
    {
        return $this->isAssigned($user, (int) $transfer->source_branch_id)
            || $this->isAssigned($user, (int) $transfer->destination_branch_id);
    }

    protected function canActForSource(User $user, BranchTransferSale $transfer): bool
    {
        return $user->can('view_all_branches')
            || $this->isAssigned($user, (int) $transfer->source_branch_id);
    }

    protected function isAssigned(User $user, int $branchId): bool
    {
        return DB::table('branch_user')
            ->where('branch_id', $branchId)
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
