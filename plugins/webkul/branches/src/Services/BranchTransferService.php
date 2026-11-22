<?php

namespace Webkul\Branch\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Enums\TransferType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Models\BranchTransferSaleHistory;
use Webkul\Branch\Models\BranchTransferSaleLine;
use Webkul\Inventory\Models\Operation;
use Webkul\Inventory\Models\ProductQuantity;
use Webkul\Inventory\Models\Warehouse;
use Webkul\Product\Enums\ProductType;
use Webkul\Product\Models\Product;
use Webkul\Security\Models\User;
use Webkul\Support\Enums\SequenceResetFrequency;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Sequence;

/**
 * Internal Transfer Sale lifecycle (plan §7.1, §7.2, §7.5).
 *
 * Phase 4 — accounting is live:
 * - ship: posts Entry 1 (out_invoice at P) + Entry 2 (COGS at C)
 *   in the same transaction as the stock transition.
 * - receive: posts Entry 3 (in_invoice at P) per receipt event.
 * - cancel after ship: reverses the ship entries to zero and
 *   returns the in-transit stock to the source branch. A transfer
 *   with any payment recorded cannot be cancelled — settle the
 *   payment first (the money is already held on the legs).
 * - A draft still has no stock or accounting effect (asserted in tests).
 *
 * Invariants enforced here (§7.2):
 * - quantity_received <= quantity_shipped <= quantity per line.
 * - quantity_returned <= quantity_received per line.
 * - A draft has no stock or accounting effect (asserted in tests).
 */
class BranchTransferService
{
    /**
     * Plan defaults (plan §10 — HASAN did not answer the Phase 2
     * questions, so these defaults apply and stay changeable later):
     * payment terms Net 30, one company bank account, Main records
     * payment, FIFO costing, same-day transfers.
     */
    public const DEFAULT_PAYMENT_TERMS_DAYS = 30;

    public function __construct(
        protected BranchTransferPriceService $priceService,
        protected TransferStockService $stockService,
        protected StockLayerService $layerService,
        protected ?InterBranchAccountingService $accountingService = null,
    ) {}

    /** The accounting service, resolved lazily for tests that build this service by hand. */
    protected function accounting(): InterBranchAccountingService
    {
        return $this->accountingService ??= app(InterBranchAccountingService::class);
    }

    /**
     * Validate a creation payload the way create() does, without
     * creating anything. The Filament create form calls this in
     * mutateFormDataBeforeCreate so the UI and the API share one set
     * of rules. Returns the resolved [source, destination, type].
     *
     * @return array{Branch, Branch, TransferType}
     */
    public function validateCreation(array $data): array
    {
        $source = Branch::withoutGlobalScopes()->findOrFail((int) ($data['source_branch_id'] ?? 0));
        $destination = Branch::withoutGlobalScopes()->findOrFail((int) ($data['destination_branch_id'] ?? 0));

        $companyId = (int) ($data['company_id'] ?? $source->company_id);

        if ((int) $source->company_id !== $companyId || (int) $destination->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'Both branches must belong to the same company.',
            ]);
        }

        $type = $this->resolveType($source, $destination, $data['type'] ?? null);

        $this->validateBranches($source, $destination, $type);
        $this->validateLines($data['lines'] ?? []);

        return [$source, $destination, $type];
    }

    /**
     * Create a draft transfer with its lines.
     *
     * Per-line transfer_price_unit may be given (override — always kept
     * as-is) or omitted (suggested from the price rules, §4.3).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): BranchTransferSale
    {
        $actor ??= Auth::user();

        [$source, $destination, $type] = $this->validateCreation($data);

        $companyId = (int) ($data['company_id'] ?? $source->company_id);

        $transferDate = isset($data['transfer_date']) ? Carbon::parse($data['transfer_date']) : now();
        $termsDays = (int) ($data['payment_terms_days'] ?? self::DEFAULT_PAYMENT_TERMS_DAYS);

        return DB::transaction(function () use ($data, $source, $destination, $companyId, $type, $transferDate, $termsDays, $actor) {
            $company = Company::query()->findOrFail($companyId);

            $transfer = BranchTransferSale::create([
                'company_id'               => $companyId,
                'source_branch_id'         => $source->getKey(),
                'destination_branch_id'    => $destination->getKey(),
                'source_warehouse_id'      => $source->warehouse_id,
                'destination_warehouse_id' => $destination->warehouse_id,
                'parent_transfer_id'       => $data['parent_transfer_id'] ?? null,
                'name'                     => $this->generateNumber($companyId, $transferDate),
                'reference'                => $data['reference'] ?? null,
                'type'                     => $type->value,
                'return_reason'            => $data['return_reason'] ?? ($type === TransferType::Return ? 'unsold' : null),
                'status'                   => TransferStatus::Draft->value,
                'transfer_date'            => $transferDate->toDateString(),
                'expected_date'            => $data['expected_date'] ?? null,
                'currency_id'              => $company->currency_id,
                'payment_terms_days'       => $termsDays,
                'due_date'                 => $transferDate->copy()->addDays($termsDays)->toDateString(),
                'notes'                    => $data['notes'] ?? null,
                'creator_id'               => $actor?->getKey(),
                'created_by_branch_id'     => $data['created_by_branch_id'] ?? $source->getKey(),
            ]);

            $sort = 0;

            foreach ($data['lines'] as $lineData) {
                $product = Product::query()->findOrFail((int) $lineData['product_id']);

                $price = isset($lineData['transfer_price_unit']) && $lineData['transfer_price_unit'] !== ''
                    ? (float) $lineData['transfer_price_unit']
                    : $this->priceService->suggestPrice($product, $destination, $transferDate);

                $transfer->lines()->create([
                    'product_id'          => $product->getKey(),
                    'lot_id'              => $lineData['lot_id'] ?? null,
                    'quantity'            => (float) $lineData['quantity'],
                    'uom_id'              => $product->uom_id,
                    'transfer_price_unit' => $price,
                    'credit_value_unit'   => isset($lineData['credit_value_unit']) && $lineData['credit_value_unit'] !== ''
                        ? (float) $lineData['credit_value_unit']
                        : null,
                    'price_subtotal'      => (float) $lineData['quantity'] * $price,
                    'sort'                => $sort++,
                ]);
            }

            $transfer->refresh();
            $transfer->recomputeTotals();
            $this->recordHistory($transfer, null, TransferStatus::Draft, $actor, $data['notes'] ?? null);

            return $transfer->refresh();
        });
    }

    /**
     * Confirm a draft. Hard-blocks on shortage at the source warehouse
     * and on a missing Main approval for Sub → Sub transfers.
     *
     * Phase 3: creates the outbound Operation (Stock → Transit) and
     * reserves the stock. Return transfers skip this step — their
     * outbound operation is built at ship time from the original
     * transfer's receipt operation (engine return mechanics).
     */
    public function confirm(BranchTransferSale $transfer, ?User $actor = null): BranchTransferSale
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = $transfer->fresh();

            $this->assertStatus($transfer, [TransferStatus::Draft]);

            $destination = Branch::withoutGlobalScopes()->findOrFail($transfer->destination_branch_id);

            // Service transfers move no stock, so a service branch
            // without a warehouse is a valid destination (same
            // exemption as validateBranches at create time).
            if ($transfer->type !== TransferType::Service && ! $destination->canBeTransferDestination()) {
                throw ValidationException::withMessages([
                    'destination_branch_id' => 'The destination branch is inactive or has no warehouse.',
                ]);
            }

            if (! $transfer->isApproved()) {
                throw ValidationException::withMessages([
                    'approved_by' => 'A Sub → Sub transfer needs Main approval before it can be confirmed.',
                ]);
            }

            $shortages = $this->checkAvailability($transfer);

            if ($shortages !== []) {
                throw ValidationException::withMessages([
                    'lines' => 'Not enough stock at the source branch: '.implode('; ', $shortages),
                ]);
            }

            if ($this->stockService->movesStock($transfer) && ! $this->isEngineReturn($transfer)) {
                $source = Branch::withoutGlobalScopes()->findOrFail($transfer->source_branch_id);
                $sourceLocation = $this->stockService->stockLocation($source);

                foreach ($this->stockService->stockLines($transfer) as $line) {
                    $this->layerService->ensureLayers($source, $line->product, $sourceLocation->getKey());
                }

                $this->stockService->createOutboundOperation($transfer);
            }

            $this->transition($transfer, TransferStatus::Confirmed, $actor, 'Transfer confirmed.');

            return $transfer->refresh();
        });
    }

    /**
     * Ship a confirmed transfer.
     *
     * Phase 3: consumes the FIFO layers, captures cost_price_unit
     * (C) per line, and completes the outbound Operation so the
     * stock leaves the source branch into transit.
     * Phase 4 hook: post Entry (a) — out_invoice + COGS/stock-out — here.
     *
     * @param  array<int, float>  $shippedQuantities  line id => shipped qty (default: full quantity)
     */
    public function ship(BranchTransferSale $transfer, ?User $actor = null, array $shippedQuantities = []): BranchTransferSale
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($transfer, $actor, $shippedQuantities) {
            $transfer = $transfer->fresh();

            $this->assertStatus($transfer, [TransferStatus::Confirmed]);

            if (! $transfer->isApproved()) {
                throw ValidationException::withMessages([
                    'approved_by' => 'A Sub → Sub transfer needs Main approval before it can be shipped.',
                ]);
            }

            foreach ($transfer->lines as $line) {
                $shipped = (float) ($shippedQuantities[$line->getKey()] ?? $line->quantity);

                if ($shipped <= 0 || $shipped > (float) $line->quantity) {
                    throw ValidationException::withMessages([
                        'lines' => "Shipped quantity for line {$line->getKey()} must be between 0 and the ordered quantity.",
                    ]);
                }

                $line->forceFill(['quantity_shipped' => $shipped])->save();
            }

            if ($this->stockService->movesStock($transfer)) {
                if (! $transfer->operation_id && $this->isEngineReturn($transfer)) {
                    $original = BranchTransferSale::query()->findOrFail($transfer->parent_transfer_id);

                    $this->stockService->createReturnOperation($transfer, $original);
                }

                if (! $transfer->operation_id) {
                    $source = Branch::withoutGlobalScopes()->findOrFail($transfer->source_branch_id);
                    $sourceLocation = $this->stockService->stockLocation($source);

                    foreach ($this->stockService->stockLines($transfer) as $line) {
                        $this->layerService->ensureLayers($source, $line->product, $sourceLocation->getKey());
                    }

                    $this->stockService->createOutboundOperation($transfer->refresh());
                }

                $this->stockService->shipOutboundOperation(
                    $transfer->refresh(),
                    $shippedQuantities,
                );
            }

            $source = Branch::withoutGlobalScopes()->find($transfer->source_branch_id);

            // Phase 4: post Entry 1 (out_invoice at P) + Entry 2
            // (COGS at C) in the same transaction as the stock move.
            // The FIFO cost per line is captured by shipOutboundOperation
            // just above, so postShipEntries sees the final C.
            $this->accounting()->postShipEntries($transfer->refresh());

            $this->transition($transfer, TransferStatus::InTransit, $actor, 'Goods shipped from '.($source?->name ?? 'source branch').'.');

            return $transfer->refresh();
        });
    }

    /**
     * Receive goods at the destination branch.
     *
     * Phase 3: creates and completes a receipt Operation (Transit →
     * destination Stock) and creates the destination stock layers
     * at the transfer price P (original cost C kept on the layer).
     * Phase 4 hook: post Entry (b) — in_invoice for received qty — here.
     *
     * @param  array<int, float>  $receivedQuantities  line id => received qty (default: shipped qty)
     */
    public function receive(BranchTransferSale $transfer, ?User $actor = null, array $receivedQuantities = []): BranchTransferSale
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($transfer, $actor, $receivedQuantities) {
            $transfer = $transfer->fresh();

            $this->assertStatus($transfer, [TransferStatus::InTransit, TransferStatus::PartiallyReceived]);

            // Quantities received in THIS call, captured before the
            // lines are updated — the receipt operation and the new
            // stock layers are built from exactly these numbers.
            $receivedThisCall = [];

            foreach ($transfer->lines as $line) {
                $receivedThisCall[$line->getKey()] = (float) ($receivedQuantities[$line->getKey()] ?? $line->quantity_shipped);
            }

            foreach ($transfer->lines as $line) {
                $received = $receivedThisCall[$line->getKey()];

                if ($received < 0 || $received > (float) $line->quantity_shipped) {
                    throw ValidationException::withMessages([
                        'lines' => "Received quantity for line {$line->getKey()} cannot exceed the shipped quantity.",
                    ]);
                }

                $line->forceFill(['quantity_received' => (float) $line->quantity_received + $received])->save();
            }

            $transfer->refresh();

            if ($this->stockService->movesStock($transfer) && $transfer->destination_warehouse_id) {
                // A damaged return goes to Main's Scrap location after
                // Main's inspection, never into sellable stock.
                $destinationLocation = null;

                if ($transfer->type === TransferType::Return && $transfer->return_reason === 'damaged') {
                    $destinationBranch = Branch::withoutGlobalScopes()->findOrFail($transfer->destination_branch_id);
                    $destinationLocation = $this->stockService->scrapLocation($destinationBranch);
                }

                $this->stockService->createReceiptOperation($transfer->refresh(), $receivedThisCall, $destinationLocation);
            }

            // Phase 4: post Entry 3 (in_invoice at P) for the
            // quantities received in this call.
            $this->accounting()->postReceiptEntries($transfer->refresh(), $receivedThisCall);

            $status = $transfer->isFullyReceived()
                ? TransferStatus::Received
                : TransferStatus::PartiallyReceived;

            if ($status === TransferStatus::Received) {
                $transfer->forceFill(['received_date' => now()])->save();
            }

            $destination = Branch::withoutGlobalScopes()->find($transfer->destination_branch_id);

            $this->transition($transfer, $status, $actor, 'Goods received at '.($destination?->name ?? 'destination branch').'.');

            return $transfer->refresh();
        });
    }

    /**
     * Cancel a transfer.
     *
     * Draft or confirmed: releases the reservation and cancels the
     * outbound Operation (Phase 3). In transit (Phase 4): reverses
     * the ship entries to zero and returns the in-transit stock to
     * the source branch. A transfer with any payment recorded cannot
     * be cancelled — the money is already held on the payment legs,
     * so settle or reverse the payment first.
     */
    public function cancel(BranchTransferSale $transfer, ?User $actor = null, ?string $note = null): BranchTransferSale
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($transfer, $actor, $note) {
            $transfer = $transfer->fresh();

            $this->assertStatus($transfer, [TransferStatus::Draft, TransferStatus::Confirmed, TransferStatus::InTransit]);

            if ($transfer->status === TransferStatus::InTransit) {
                if ((float) $transfer->amount_paid > 0) {
                    throw ValidationException::withMessages([
                        'status' => 'This transfer already has payments recorded and cannot be cancelled. Reverse the payment first.',
                    ]);
                }

                // Reverse the accounting to zero, then bring the
                // in-transit stock home, in one transaction.
                $this->accounting()->reverseShipEntries($transfer->refresh());

                if ($this->stockService->movesStock($transfer) && $transfer->operation_id) {
                    $this->stockService->returnTransitToSource($transfer->refresh());
                }
            } elseif ($transfer->operation_id) {
                $operation = Operation::query()->find($transfer->operation_id);

                if ($operation) {
                    $this->stockService->cancelOperation($operation);
                }
            }

            $this->transition($transfer, TransferStatus::Cancelled, $actor, $note ?? 'Transfer cancelled.');

            return $transfer->refresh();
        });
    }

    /**
     * Return goods to the source branch (addendum A3: unsold return at
     * the original P; use returnDamaged() for a damaged return).
     *
     * Creates a linked return transfer (type return, branches swapped,
     * lines at the original transfer price) in draft, and marks the
     * original Returned when everything received has been returned.
     *
     * @param  array<int, float>  $returnQuantities  line id => qty to return (default: all received, not yet returned)
     */
    public function return(BranchTransferSale $transfer, ?User $actor = null, array $returnQuantities = []): BranchTransferSale
    {
        return $this->createReturn($transfer, $actor, $returnQuantities, 'unsold', []);
    }

    /**
     * Return DAMAGED goods to Main (addendum A3, Phase 5).
     *
     * Same document as an unsold return, but the return_reason is
     * 'damaged' and each line carries the agreed credit value V
     * (credit_value_unit). The Sub's payable falls only by V; the
     * difference (P − V) is the Sub's Inventory Damage Loss. The
     * goods go to Main's Scrap location on receipt, never into
     * sellable stock.
     *
     * @param  array<int, float>  $returnQuantities  line id => qty to return (default: all received, not yet returned)
     * @param  array<int, float>  $creditValues  line id => agreed credit per unit (default: full P)
     */
    public function returnDamaged(BranchTransferSale $transfer, ?User $actor = null, array $returnQuantities = [], array $creditValues = []): BranchTransferSale
    {
        return $this->createReturn($transfer, $actor, $returnQuantities, 'damaged', $creditValues);
    }

    /**
     * Build a return transfer document (unsold or damaged).
     *
     * @param  array<int, float>  $returnQuantities  line id => qty to return
     * @param  array<int, float>  $creditValues  line id => agreed credit per unit (damaged only)
     */
    protected function createReturn(BranchTransferSale $transfer, ?User $actor, array $returnQuantities, string $reason, array $creditValues): BranchTransferSale
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($transfer, $actor, $returnQuantities, $reason, $creditValues) {
            $transfer = $transfer->fresh();

            $this->assertStatus($transfer, [TransferStatus::Received, TransferStatus::PartiallyReceived]);

            $returnLines = [];

            foreach ($transfer->lines as $line) {
                $returnable = (float) $line->quantity_received - (float) $line->quantity_returned;
                $qty = (float) ($returnQuantities[$line->getKey()] ?? $returnable);

                if ($qty < 0 || $qty > $returnable) {
                    throw ValidationException::withMessages([
                        'lines' => "Return quantity for line {$line->getKey()} cannot exceed the received quantity.",
                    ]);
                }

                if ($qty > 0) {
                    $credit = $reason === 'damaged'
                        ? (float) ($creditValues[$line->getKey()] ?? $line->transfer_price_unit)
                        : null;

                    if ($credit !== null && ($credit < 0 || $credit > (float) $line->transfer_price_unit)) {
                        throw ValidationException::withMessages([
                            'lines' => "Agreed credit for line {$line->getKey()} must be between 0 and the transfer price.",
                        ]);
                    }

                    $returnLines[] = [
                        'product_id'          => $line->product_id,
                        'quantity'            => $qty,
                        'transfer_price_unit' => (float) $line->transfer_price_unit,
                        'credit_value_unit'   => $credit,
                    ];
                }
            }

            if ($returnLines === []) {
                throw ValidationException::withMessages([
                    'lines' => 'Nothing left to return on this transfer.',
                ]);
            }

            $returnTransfer = $this->create([
                'company_id'            => $transfer->company_id,
                'source_branch_id'      => $transfer->destination_branch_id,
                'destination_branch_id' => $transfer->source_branch_id,
                'type'                  => TransferType::Return->value,
                'return_reason'         => $reason,
                'parent_transfer_id'    => $transfer->getKey(),
                'reference'             => $transfer->name,
                'transfer_date'         => now()->toDateString(),
                'notes'                 => $reason === 'damaged'
                    ? 'Damaged return of '.$transfer->name.' at the agreed credit value.'
                    : 'Return of '.$transfer->name.' at the original transfer price.',
                'lines'                 => $returnLines,
            ], $actor);

            foreach ($transfer->lines as $line) {
                $qty = collect($returnLines)->firstWhere('product_id', $line->product_id)['quantity'] ?? 0;

                if ($qty > 0) {
                    $line->forceFill([
                        'quantity_returned' => (float) $line->quantity_returned + (float) $qty,
                    ])->save();
                }
            }

            $transfer->refresh();

            $note = $reason === 'damaged'
                ? 'Damaged goods returned in transfer '.$returnTransfer->name.'.'
                : 'Goods returned in transfer '.$returnTransfer->name.'.';

            if ($transfer->lines->every(fn (BranchTransferSaleLine $line) => (float) $line->quantity_returned >= (float) $line->quantity_received)) {
                $this->transition($transfer, TransferStatus::Returned, $actor, $note);
            } else {
                $this->recordHistory($transfer, $transfer->status, $transfer->status, $actor, 'Partial return in transfer '.$returnTransfer->name.'.');
            }

            return $transfer->refresh();
        });
    }

    /**
     * Close the unshipped remainder of a transfer (Phase 3 gap,
     * Phase 5). A transfer that was shipped short — ordered 10,
     * shipped 8 — stays open forever because isFullyReceived()
     * compares against the ordered quantity. Closing short marks
     * the unshipped remainder closed with a history entry.
     *
     * No stock or accounting effect for the unshipped part: those
     * units never left the source branch (the outbound move was
     * resized at ship), so they are simply available again. The
     * shipped part is untouched — it must still be received (or
     * cancelled) on its own. When everything shipped has been
     * received, the transfer becomes Received.
     */
    public function closeShort(BranchTransferSale $transfer, ?User $actor = null, ?string $note = null): BranchTransferSale
    {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($transfer, $actor, $note) {
            $transfer = $transfer->fresh();

            $this->assertStatus($transfer, [TransferStatus::InTransit, TransferStatus::PartiallyReceived]);

            if ($transfer->is_closed_short) {
                throw ValidationException::withMessages([
                    'status' => "Transfer {$transfer->name} was already closed short.",
                ]);
            }

            $shortParts = [];

            foreach ($transfer->lines as $line) {
                $short = round((float) $line->quantity - (float) $line->quantity_shipped, 4);

                if ($short > 0) {
                    $shortParts[] = "{$line->product?->name} × {$short}";
                }
            }

            if ($shortParts === []) {
                throw ValidationException::withMessages([
                    'status' => "Transfer {$transfer->name} has no unshipped remainder to close.",
                ]);
            }

            $transfer->forceFill([
                'is_closed_short' => true,
                'closed_short_at' => now(),
            ])->save();

            $message = 'Closed short — unshipped remainder will not be delivered: '.implode(', ', $shortParts).'.';

            if ($note) {
                $message .= ' '.$note;
            }

            if ($transfer->refresh()->isFullyReceived()) {
                $transfer->forceFill(['received_date' => now()])->save();
                $this->transition($transfer, TransferStatus::Received, $actor, $message);
            } else {
                $this->recordHistory($transfer, $transfer->status, $transfer->status, $actor, $message);
            }

            return $transfer->refresh();
        });
    }

    /**
     * Main approval for a Sub → Sub (rebalance) transfer. Only a user
     * who can see all branches (Main / head office) may approve.
     */
    public function approve(BranchTransferSale $transfer, ?User $actor = null): BranchTransferSale
    {
        $actor ??= Auth::user();

        if (! $actor || ! $actor->can('view_all_branches')) {
            throw ValidationException::withMessages([
                'approved_by' => 'Only a Main / head-office user can approve a Sub → Sub transfer.',
            ]);
        }

        if (! $transfer->requiresMainApproval()) {
            throw ValidationException::withMessages([
                'approved_by' => 'Only Sub → Sub transfers need Main approval.',
            ]);
        }

        return DB::transaction(function () use ($transfer, $actor) {
            $transfer = $transfer->fresh();

            $transfer->forceFill([
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
            ])->save();

            $this->recordHistory($transfer, $transfer->status, $transfer->status, $actor, 'Main approval granted by '.$actor->name.'.');

            return $transfer->refresh();
        });
    }

    /**
     * On-hand available quantity of a product at a warehouse
     * (quantity minus reserved, summed over the warehouse locations).
     */
    public function availableQuantity(Product $product, ?int $warehouseId): float
    {
        if (! $warehouseId) {
            return 0.0;
        }

        $warehouse = Warehouse::query()->find($warehouseId);

        if (! $warehouse) {
            return 0.0;
        }

        $available = 0.0;

        foreach ($warehouse->locations as $location) {
            try {
                $available += ProductQuantity::availableFor($product, $location, strict: true);
            } catch (\Throwable) {
                $available += (float) ProductQuantity::query()
                    ->where('product_id', $product->getKey())
                    ->where('location_id', $location->getKey())
                    ->sum(DB::raw('quantity - reserved_quantity'));
            }
        }

        return max(0.0, $available);
    }

    /**
     * Shortage list for confirm(): "Product name (need X, have Y)".
     * Service-type products hold no stock, so they are never short.
     *
     * @return string[]
     */
    public function checkAvailability(BranchTransferSale $transfer): array
    {
        $shortages = [];

        $transfer->loadMissing(['lines.product', 'sourceBranch']);

        foreach ($transfer->lines as $line) {
            $product = $line->product;

            if (! $product || $product->type === ProductType::SERVICE) {
                continue;
            }

            $available = $this->availableQuantity($product, $transfer->source_warehouse_id);

            if ($available + 0.0001 < (float) $line->quantity) {
                $shortages[] = $product->name.' (need '.(float) $line->quantity.', have '.$available.')';
            }
        }

        return $shortages;
    }

    /**
     * A return transfer whose stock must come back through the
     * engine's return mechanics: type return with a parent transfer.
     * Its outbound operation is built at ship time from the original
     * transfer's receipt operation instead of at confirm time.
     */
    protected function isEngineReturn(BranchTransferSale $transfer): bool
    {
        return $transfer->type === TransferType::Return
            && $transfer->parent_transfer_id !== null;
    }

    protected function resolveType(Branch $source, Branch $destination, ?string $requestedType): TransferType
    {
        if ($requestedType) {
            $type = TransferType::tryFrom($requestedType)
                ?? throw ValidationException::withMessages(['type' => 'Unknown transfer type.']);

            return $type;
        }

        if ($destination->isService()) {
            return TransferType::Service;
        }

        if ($source->isMain() && $destination->isSub()) {
            return TransferType::Supply;
        }

        if ($source->isSub() && $destination->isSub()) {
            return TransferType::Rebalance;
        }

        if ($source->isSub() && $destination->isMain()) {
            return TransferType::Return;
        }

        throw ValidationException::withMessages([
            'type' => 'Cannot determine the transfer type for these branches.',
        ]);
    }

    protected function validateBranches(Branch $source, Branch $destination, TransferType $type): void
    {
        if ($source->getKey() === $destination->getKey()) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'Source and destination branches must be different.',
            ]);
        }

        match ($type) {
            TransferType::Supply    => $this->assertBranchTypes($source, $destination, 'main', 'sub'),
            TransferType::Rebalance => $this->assertBranchTypes($source, $destination, 'sub', 'sub'),
            TransferType::Return    => $this->assertBranchTypes($source, $destination, 'sub', 'main'),
            TransferType::Service   => $this->assertServiceDestination($source, $destination),
        };

        if (! $destination->is_active) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'The destination branch is inactive.',
            ]);
        }

        if ($type !== TransferType::Service && ! $destination->canBeTransferDestination()) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'The destination branch cannot receive transfers (inactive or no warehouse).',
            ]);
        }
    }

    protected function assertBranchTypes(Branch $source, Branch $destination, string $expectedSource, string $expectedDestination): void
    {
        if ($source->type->value !== $expectedSource || $destination->type->value !== $expectedDestination) {
            throw ValidationException::withMessages([
                'destination_branch_id' => "This transfer type needs a {$expectedSource} source and a {$expectedDestination} destination.",
            ]);
        }
    }

    protected function assertServiceDestination(Branch $source, Branch $destination): void
    {
        if (! $destination->isService()) {
            throw ValidationException::withMessages([
                'destination_branch_id' => 'A service transfer must go to a service branch.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function validateLines(array $lines): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one product line.']);
        }

        Validator::make(['lines' => $lines], [
            'lines'                       => ['required', 'array', 'min:1'],
            'lines.*.product_id'          => ['required', 'integer', 'exists:products_products,id'],
            'lines.*.quantity'            => ['required', 'numeric', 'gt:0'],
            'lines.*.transfer_price_unit' => ['nullable', 'numeric', 'gte:0'],
        ])->validate();

        foreach ($lines as $line) {
            $product = Product::query()->findOrFail((int) $line['product_id']);

            // A configurable parent has no stock of its own — pick a
            // variant instead. A variant (parent_id set, not itself
            // configurable) is a real stockable product and may be
            // transferred.
            if ($product->is_configurable) {
                throw ValidationException::withMessages([
                    'lines' => "Product {$product->name} is a configurable parent — pick a variant instead.",
                ]);
            }

            // A goods product that is not storable holds no stock, so
            // the engine would silently move nothing for it. Block it
            // here with a clear message instead (service products are
            // exempt — they never move stock by design).
            if ($product->type !== ProductType::SERVICE && ! $product->is_storable) {
                throw ValidationException::withMessages([
                    'lines' => "Product {$product->name} is not storable — enable stock tracking on the product before transferring it.",
                ]);
            }
        }
    }

    protected function assertStatus(BranchTransferSale $transfer, array $allowed): void
    {
        if (! in_array($transfer->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Transfer {$transfer->name} is {$transfer->status->value}; expected ".implode(' or ', array_map(fn (TransferStatus $s) => $s->value, $allowed)).'.',
            ]);
        }
    }

    protected function transition(BranchTransferSale $transfer, TransferStatus $to, ?User $actor, ?string $note = null): void
    {
        $from = $transfer->status;

        $transfer->forceFill(['status' => $to->value])->save();

        $this->recordHistory($transfer, $from, $to, $actor, $note);
    }

    protected function recordHistory(BranchTransferSale $transfer, ?TransferStatus $from, TransferStatus $to, ?User $actor, ?string $note = null): void
    {
        BranchTransferSaleHistory::create([
            'transfer_id' => $transfer->getKey(),
            'user_id'     => $actor?->getKey(),
            'from_status' => $from?->value,
            'to_status'   => $to->value,
            'note'        => $note,
        ]);
    }

    /**
     * Transfer number from the Sequence table (plan §7.5), e.g.
     * ITS/2026/0001. The sequence row is created on first use. Public
     * so the model can number transfers created through the Filament
     * form, which bypasses create().
     */
    public function generateNumber(int $companyId, Carbon $date): string
    {
        $sequence = Sequence::query()->firstOrCreate(
            ['code' => 'branch_transfer_sale', 'company_id' => $companyId],
            [
                'name'            => 'Internal Transfer Sales',
                'prefix'          => 'ITS/%(year)/',
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
