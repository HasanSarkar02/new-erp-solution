<?php

namespace Webkul\Branch\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Enums\WriteOffStatus;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchStockWriteOff;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Inventory\Models\Scrap;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\SequenceResetFrequency;
use Webkul\Support\Models\Sequence;

/**
 * Lost / missing stock write-offs (addendum A3, Phase 5).
 *
 * A write-off is raised against a transfer the Sub branch received:
 * goods that disappeared at the Sub. The Sub still owes Main — the
 * payable does NOT fall — and posts the loss at stock value P.
 * Lifecycle: draft → approved (Main) → posted (stock + ledger
 * moved) → forgiven (optional, Main only, via a separate approved
 * credit note that falls the receivable and the payable).
 *
 * Posting does three things in one transaction:
 *   1. An engine Scrap record moves the quants Sub Stock → Scrap.
 *   2. The Sub's FIFO layers are consumed at the lost quantities.
 *   3. The ledger posts Dr 645000 / Cr 110100 at P.
 */
class BranchWriteOffService
{
    public function __construct(
        protected InterBranchAccountingService $accounting,
        protected StockLayerService $layers,
        protected TransferStockService $stock,
    ) {}

    /**
     * Create a draft write-off against a received transfer.
     *
     * @param  array<int, array{product_id: int, quantity: float}>  $lines
     */
    public function create(Branch $branch, BranchTransferSale $transfer, array $lines, string $investigationNote, ?User $creator = null): BranchStockWriteOff
    {
        if ($branch->type === BranchType::Main) {
            throw ValidationException::withMessages([
                'branch_id' => 'A write-off is raised by a Sub branch for goods lost at that branch. Main losses are booked directly, not as a write-off.',
            ]);
        }

        if ((int) $transfer->destination_branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'transfer_id' => "Transfer {$transfer->name} was not received by {$branch->name}.",
            ]);
        }

        if ($transfer->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'transfer_id' => "Transfer {$transfer->name} has no lines.",
            ]);
        }

        $prepared = $this->prepareLines($transfer, $lines);

        if ($prepared === []) {
            throw ValidationException::withMessages([
                'lines' => 'No write-off lines were given.',
            ]);
        }

        return DB::transaction(function () use ($branch, $transfer, $prepared, $investigationNote, $creator) {
            $writeOff = BranchStockWriteOff::create([
                'company_id'         => $branch->company_id,
                'branch_id'          => $branch->getKey(),
                'transfer_id'        => $transfer->getKey(),
                'name'               => $this->generateNumber($branch->company_id, now()),
                'status'             => WriteOffStatus::Draft,
                'investigation_note' => $investigationNote,
                'amount_total'       => collect($prepared)->sum('loss_subtotal'),
                'creator_id'         => $creator?->getKey() ?? auth()->id(),
            ]);

            foreach ($prepared as $line) {
                $writeOff->lines()->create($line);
            }

            return $writeOff->refresh();
        });
    }

    /**
     * Resolve the requested lines against the transfer: unit value
     * is always the transfer price P, and the quantity may not
     * exceed what the branch received and has not already returned
     * or written off.
     *
     * @param  array<int, array{product_id: int, quantity: float}>  $lines
     * @return array<int, array<string, mixed>>
     */
    protected function prepareLines(BranchTransferSale $transfer, array $lines): array
    {
        $prepared = [];

        foreach ($lines as $requested) {
            $productId = (int) $requested['product_id'];
            $quantity = round((float) $requested['quantity'], 4);

            if ($quantity <= 0) {
                continue;
            }

            $transferLine = $transfer->lines->firstWhere('product_id', $productId);

            if (! $transferLine) {
                throw ValidationException::withMessages([
                    'lines' => "Product #{$productId} was not on transfer {$transfer->name}.",
                ]);
            }

            $alreadyWrittenOff = (float) $transferLine->writeOffLines()
                ->whereHas('writeOff', fn ($query) => $query->whereIn('status', [WriteOffStatus::Approved, WriteOffStatus::Posted, WriteOffStatus::Forgiven]))
                ->sum('quantity');

            $available = round(
                (float) $transferLine->quantity_received
                - (float) $transferLine->quantity_returned
                - $alreadyWrittenOff,
                4
            );

            if ($quantity > $available) {
                throw ValidationException::withMessages([
                    'lines' => "Cannot write off {$quantity} of {$transferLine->product?->name}: only {$available} received and unaccounted for on {$transfer->name}.",
                ]);
            }

            $unitValue = (float) $transferLine->transfer_price_unit;

            $prepared[] = [
                'product_id'       => $productId,
                'transfer_line_id' => $transferLine->getKey(),
                'quantity'         => $quantity,
                'unit_value'       => $unitValue,
                'loss_subtotal'    => round($quantity * $unitValue, 4),
            ];
        }

        return $prepared;
    }

    /**
     * Approve a draft write-off. Main only — enforced by the
     * BranchStockWriteOffPolicy in the UI; the service records the
     * approver.
     */
    public function approve(BranchStockWriteOff $writeOff, ?User $actor = null): BranchStockWriteOff
    {
        if ($writeOff->status !== WriteOffStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => "Only a draft write-off can be approved (current: {$writeOff->status->value}).",
            ]);
        }

        $writeOff->forceFill([
            'status'      => WriteOffStatus::Approved,
            'approved_by' => $actor?->getKey() ?? auth()->id(),
            'approved_at' => now(),
        ])->save();

        return $writeOff->refresh();
    }

    /**
     * Post an approved write-off: scrap the stock, consume the FIFO
     * layers, and post the loss to the ledger — one transaction.
     */
    public function post(BranchStockWriteOff $writeOff, ?User $actor = null): BranchStockWriteOff
    {
        if ($writeOff->status !== WriteOffStatus::Approved) {
            throw ValidationException::withMessages([
                'status' => "Only an approved write-off can be posted (current: {$writeOff->status->value}).",
            ]);
        }

        $writeOff->loadMissing(['branch', 'lines.product']);

        return DB::transaction(function () use ($writeOff) {
            $branch = $writeOff->branch;
            $stockLocation = $this->stock->stockLocation($branch);
            $scrapLocation = $this->stock->scrapLocation($branch);

            foreach ($writeOff->lines as $line) {
                $scrap = Scrap::create([
                    'name'                    => "Write-off {$writeOff->name}",
                    'product_id'              => $line->product_id,
                    'uom_id'                  => $line->product->uom_id,
                    'qty'                     => (float) $line->quantity,
                    'source_location_id'      => $stockLocation->getKey(),
                    'destination_location_id' => $scrapLocation->getKey(),
                    'company_id'              => $writeOff->company_id,
                    'creator_id'              => auth()->id(),
                ]);

                if (! $scrap->validate()) {
                    throw ValidationException::withMessages([
                        'lines' => "Could not scrap {$line->quantity} of {$line->product?->name} at {$branch->name}: the stock is not there.",
                    ]);
                }

                $line->forceFill(['inventory_scrap_id' => $scrap->getKey()])->save();

                $this->layers->consumeFifoForWriteOff(
                    $branch,
                    $line,
                    (float) $line->quantity,
                    $stockLocation->getKey(),
                );
            }

            $this->accounting->postWriteOffEntries($writeOff);

            $writeOff->forceFill([
                'status'    => WriteOffStatus::Posted,
                'posted_at' => now(),
            ])->save();

            return $writeOff->refresh();
        });
    }

    /**
     * Main formally forgives the debt: the Sub's payable falls and
     * Main absorbs the loss (two mirrored refund invoices, which
     * reconcile against the parent transfer's invoices so the
     * Branch Profile balances fall too).
     */
    public function forgive(BranchStockWriteOff $writeOff, ?User $actor = null): BranchStockWriteOff
    {
        if ($writeOff->status !== WriteOffStatus::Posted) {
            throw ValidationException::withMessages([
                'status' => "Only a posted write-off can be forgiven (current: {$writeOff->status->value}).",
            ]);
        }

        return DB::transaction(function () use ($writeOff, $actor) {
            $this->accounting->postWriteOffForgivenessEntries($writeOff);

            $writeOff->forceFill([
                'status'      => WriteOffStatus::Forgiven,
                'forgiven_by' => $actor?->getKey() ?? auth()->id(),
                'forgiven_at' => now(),
            ])->save();

            return $writeOff->refresh();
        });
    }

    public function generateNumber(int $companyId, Carbon $date): string
    {
        $sequence = Sequence::query()->firstOrCreate(
            ['code' => 'branch_stock_write_off', 'company_id' => $companyId],
            [
                'name'            => 'Branch Stock Write-offs',
                'prefix'          => 'WOF/%(year)/',
                'suffix'          => null,
                'padding'         => 4,
                'next_number'     => 1,
                'step'            => 1,
                'reset_frequency' => SequenceResetFrequency::YEARLY,
            ],
        );

        return $sequence->consumeNumber($date);
    }
}
