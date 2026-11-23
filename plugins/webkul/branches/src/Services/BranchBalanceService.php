<?php

namespace Webkul\Branch\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Branch\Models\Branch;

/**
 * Branch balances (plan §5.3).
 *
 * Balances are always read from invoice residuals — never from a
 * stored balance field. Receivable = what Sub branches owe Main
 * (posted internal out_invoices, partner = the Sub); payable =
 * what Main owes a Sub on returns (posted internal in_invoices).
 *
 * Open refunds are netted (Phase 5): a return posted after the
 * parent was fully paid leaves the refunds open — the Sub is owed
 * the money back — so the headline balances subtract open
 * out_refund / in_refund residuals. Fully reconciled refunds have
 * residual 0 and change nothing.
 */
class BranchBalanceService
{
    /**
     * Outstanding receivable for one Sub branch: Main's posted
     * internal out_invoices to that Sub's partner, residual sum,
     * less open out_refunds (returns not yet settled in cash).
     */
    public function receivableOutstanding(Branch $branch): float
    {
        if ($branch->is_main) {
            return round(
                $this->residualSum(MoveType::OUT_INVOICE, null, null)
                - $this->residualSum(MoveType::OUT_REFUND, null, null),
                4
            );
        }

        if (! $branch->partner_id) {
            return 0.0;
        }

        return round(
            $this->residualSum(MoveType::OUT_INVOICE, $branch->partner_id, null)
            - $this->residualSum(MoveType::OUT_REFUND, $branch->partner_id, null),
            4
        );
    }

    /**
     * Outstanding payable for one Sub branch: posted internal
     * in_invoices on that branch (partner = Main), residual sum,
     * less open in_refunds (returns not yet settled in cash).
     * Move residuals are positive for both invoice directions — the
     * signed balance lives on the payment-term line, not the Move.
     */
    public function payableOutstanding(Branch $branch): float
    {
        if ($branch->is_main) {
            return round(
                $this->residualSum(MoveType::IN_INVOICE, null, null)
                - $this->residualSum(MoveType::IN_REFUND, null, null),
                4
            );
        }

        if (! $branch->partner_id) {
            return 0.0;
        }

        return round(
            $this->residualSum(MoveType::IN_INVOICE, null, $branch->getKey())
            - $this->residualSum(MoveType::IN_REFUND, null, $branch->getKey()),
            4
        );
    }

    /**
     * Sum of amount_residual for posted internal Moves of one type.
     * Partner-scoped for receivable (partner = the Sub), branch-
     * scoped for payable (branch = the Sub); Main totals cover all
     * internal transfers.
     */
    protected function residualSum(MoveType $moveType, ?int $partnerId, ?int $branchId): float
    {
        $query = Move::query()
            ->where('is_internal', true)
            ->where('state', MoveState::POSTED)
            ->where('move_type', $moveType)
            ->whereNotNull('branch_transfer_sale_id');

        if ($partnerId !== null) {
            $query->where('partner_id', $partnerId);
        }

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return (float) $query->sum('amount_residual');
    }

    /** Net position of a Sub branch: receivable minus payable. */
    public function netOutstanding(Branch $branch): float
    {
        return round($this->receivableOutstanding($branch) - $this->payableOutstanding($branch), 4);
    }

    /** Total transferred to a branch in a period (shipped value at P). */
    public function totalTransferred(Branch $branch, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): float
    {
        $query = DB::table('branch_transfer_sales')
            ->where('destination_branch_id', $branch->getKey())
            ->whereNull('deleted_at');

        if ($from) {
            $query->where('transfer_date', '>=', $from->format('Y-m-d'));
        }

        if ($to) {
            $query->where('transfer_date', '<=', $to->format('Y-m-d'));
        }

        return (float) $query->sum('amount_total');
    }

    /** Total paid by a branch (allocations on its payments). */
    public function totalPaid(Branch $branch): float
    {
        return (float) DB::table('inter_branch_payments')
            ->where('payer_branch_id', $branch->getKey())
            ->sum('amount');
    }

    /** Overdue receivable for a Sub branch (past due date, still open). */
    public function overdueReceivable(Branch $branch): float
    {
        if ($branch->is_main || ! $branch->partner_id) {
            return 0.0;
        }

        return (float) Move::query()
            ->where('is_internal', true)
            ->where('state', MoveState::POSTED)
            ->where('move_type', MoveType::OUT_INVOICE)
            ->where('partner_id', $branch->partner_id)
            ->where('invoice_date_due', '<', now()->format('Y-m-d'))
            ->where('amount_residual', '>', 0)
            ->sum('amount_residual');
    }
}
