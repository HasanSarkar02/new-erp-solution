<?php

namespace Webkul\Branch\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Models\Move;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Enums\TransferType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Support\Models\Company;

/**
 * Prove the internal mirror holds (plan §5.5): for every transfer,
 * the source's receivable (out_invoice residual) equals the
 * destination's payable (in_invoice residual, sign-flipped).
 *
 * Also verifies every internal Move balances (sum debit == sum
 * credit). Exits non-zero and lists the mismatches when it fails.
 */
class CheckBalancesCommand extends Command
{
    protected $signature = 'branches:check-balances
        {--company= : Company id (defaults to the first company)}';

    protected $description = 'Prove receivable (source) == payable (destination) for every internal transfer';

    public function handle(): int
    {
        $companyId = $this->option('company')
            ?? Company::query()->orderBy('id')->value('id');

        $transfers = BranchTransferSale::query()
            ->where('company_id', $companyId)
            ->whereNotNull('out_invoice_id')
            ->with(['outInvoice', 'inInvoice', 'sourceBranch', 'destinationBranch'])
            ->get();

        $mismatches = [];
        $checked = 0;

        foreach ($transfers as $transfer) {
            $checked++;

            // A cancelled transfer is fully reversed; its lines still
            // show shipped quantities, so it must not count as
            // in-transit stock.
            if ($transfer->status === TransferStatus::Cancelled) {
                continue;
            }

            // A return transfer is a mirror of the normal flow: its
            // out_refund residual (Main owes the Sub) must equal its
            // in_refund residual (the Sub's credit). The normal
            // out_invoice − in_invoice formula does not apply — the
            // return has no invoices, only refunds. (Phase 5 fix:
            // the UAT return after full payment tripped this check.)
            if ($transfer->type === TransferType::Return) {
                $outRefund = (float) Move::query()
                    ->where('is_internal', true)
                    ->where('state', MoveState::POSTED)
                    ->where('move_type', MoveType::OUT_REFUND)
                    ->where('branch_transfer_sale_id', $transfer->getKey())
                    ->sum('amount_residual');

                $inRefund = (float) Move::query()
                    ->where('is_internal', true)
                    ->where('state', MoveState::POSTED)
                    ->where('move_type', MoveType::IN_REFUND)
                    ->where('branch_transfer_sale_id', $transfer->getKey())
                    ->sum('amount_residual');

                if (abs($outRefund - $inRefund) > 0.0001) {
                    $mismatches[] = sprintf(
                        '%s (%s → %s, return): out_refund residual %.4f != in_refund residual %.4f',
                        $transfer->name,
                        $transfer->sourceBranch?->code,
                        $transfer->destinationBranch?->code,
                        $outRefund,
                        $inRefund,
                    );
                }

                continue;
            }

            $outResidual = (float) ($transfer->outInvoice?->amount_residual ?? 0);

            // A transfer can have several in_invoices (one per
            // receipt event); the payable is their residual sum.
            $inResidual = (float) Move::query()
                ->where('is_internal', true)
                ->where('state', MoveState::POSTED)
                ->where('move_type', MoveType::IN_INVOICE)
                ->where('branch_transfer_sale_id', $transfer->getKey())
                ->sum('amount_residual');

            $hasInInvoice = $transfer->inInvoice !== null
                || Move::query()
                    ->where('branch_transfer_sale_id', $transfer->getKey())
                    ->where('move_type', MoveType::IN_INVOICE)
                    ->exists();

            // A shipped-but-not-yet-received transfer legitimately
            // has no in_invoice; its payable appears at receive.
            if (! $hasInInvoice) {
                continue;
            }

            // Receivable − payable must equal the value still in
            // transit (shipped but not received), not zero — a
            // partial receipt leaves a legitimate in-transit gap.
            $inTransit = $transfer->lines->sum(
                fn ($line) => round(((float) $line->quantity_shipped - (float) $line->quantity_received) * (float) $line->transfer_price_unit, 4)
            );

            if (abs(($outResidual - $inResidual) - $inTransit) > 0.0001) {
                $mismatches[] = sprintf(
                    '%s (%s → %s): receivable %.4f − payable %.4f = %.4f, expected in-transit %.4f',
                    $transfer->name,
                    $transfer->sourceBranch?->code,
                    $transfer->destinationBranch?->code,
                    $outResidual,
                    $inResidual,
                    $outResidual - $inResidual,
                    $inTransit,
                );
            }
        }

        // Every internal Move must balance.
        $unbalanced = Move::query()
            ->where('is_internal', true)
            ->where('state', MoveState::POSTED)
            ->where('company_id', $companyId)
            ->get()
            ->filter(function (Move $move) {
                $debit = (float) $move->lines()->sum('debit');
                $credit = (float) $move->lines()->sum('credit');

                return abs($debit - $credit) > 0.0001;
            });

        foreach ($unbalanced as $move) {
            $mismatches[] = sprintf(
                'Unbalanced Move %s (%s): debit %.4f != credit %.4f',
                $move->name,
                $move->move_type->value ?? $move->move_type,
                (float) $move->lines()->sum('debit'),
                (float) $move->lines()->sum('credit'),
            );
        }

        // Branch-level mirror: Main's receivable vs each Sub's payable.
        // The gap must equal the value in transit to that branch.
        foreach (Branch::query()->where('company_id', $companyId)->where('is_main', false)->get() as $branch) {
            $receivable = (float) Move::query()
                ->where('is_internal', true)
                ->where('state', MoveState::POSTED)
                ->where('move_type', MoveType::OUT_INVOICE)
                ->where('partner_id', $branch->partner_id)
                ->sum('amount_residual');

            $payable = (float) Move::query()
                ->where('is_internal', true)
                ->where('state', MoveState::POSTED)
                ->where('move_type', MoveType::IN_INVOICE)
                ->where('branch_id', $branch->getKey())
                ->sum('amount_residual');

            $inTransit = (float) DB::table('branch_transfer_sale_lines')
                ->join('branch_transfer_sales', 'branch_transfer_sales.id', '=', 'branch_transfer_sale_lines.transfer_id')
                ->where('branch_transfer_sales.destination_branch_id', $branch->getKey())
                ->where('branch_transfer_sales.company_id', $companyId)
                ->where('branch_transfer_sales.status', '!=', 'cancelled')
                ->whereNull('branch_transfer_sales.deleted_at')
                ->selectRaw('SUM((quantity_shipped - quantity_received) * transfer_price_unit) as total')
                ->value('total');

            if (abs(($receivable - $payable) - $inTransit) > 0.0001) {
                $mismatches[] = sprintf(
                    'Branch %s: receivable %.4f − payable %.4f = %.4f, expected in-transit %.4f',
                    $branch->code,
                    $receivable,
                    $payable,
                    $receivable - $payable,
                    $inTransit,
                );
            }
        }

        if ($mismatches === []) {
            $this->info("OK: {$checked} transfers checked, receivable == payable everywhere.");

            return self::SUCCESS;
        }

        foreach ($mismatches as $mismatch) {
            $this->error($mismatch);
        }

        return self::FAILURE;
    }
}
