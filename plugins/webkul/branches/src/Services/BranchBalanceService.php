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
 */
class BranchBalanceService
{
    /**
     * Outstanding receivable for one Sub branch: Main's posted
     * internal out_invoices to that Sub's partner, residual sum.
     */
    public function receivableOutstanding(Branch $branch): float
    {
        if ($branch->is_main) {
            return (float) Move::query()
                ->where('is_internal', true)
                ->where('state', MoveState::POSTED)
                ->where('move_type', MoveType::OUT_INVOICE)
                ->whereNotNull('branch_transfer_sale_id')
                ->sum('amount_residual');
        }

        if (! $branch->partner_id) {
            return 0.0;
        }

        return (float) Move::query()
            ->where('is_internal', true)
            ->where('state', MoveState::POSTED)
            ->where('move_type', MoveType::OUT_INVOICE)
            ->where('partner_id', $branch->partner_id)
            ->sum('amount_residual');
    }

    /**
     * Outstanding payable for one Sub branch: posted internal
     * in_invoices on that branch (partner = Main), residual sum.
     * Move residuals are positive for both invoice directions — the
     * signed balance lives on the payment-term line, not the Move.
     */
    public function payableOutstanding(Branch $branch): float
    {
        if ($branch->is_main) {
            return (float) Move::query()
                ->where('is_internal', true)
                ->where('state', MoveState::POSTED)
                ->where('move_type', MoveType::IN_INVOICE)
                ->whereNotNull('branch_transfer_sale_id')
                ->sum('amount_residual');
        }

        if (! $branch->partner_id) {
            return 0.0;
        }

        return (float) Move::query()
            ->where('is_internal', true)
            ->where('state', MoveState::POSTED)
            ->where('move_type', MoveType::IN_INVOICE)
            ->where('branch_id', $branch->getKey())
            ->sum('amount_residual');
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
