<?php

namespace Webkul\Branch\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Webkul\Account\Enums\DisplayType;
use Webkul\Account\Enums\MoveState;
use Webkul\Account\Enums\MoveType;
use Webkul\Account\Enums\PaymentStatus;
use Webkul\Account\Enums\PaymentType;
use Webkul\Account\Models\Account;
use Webkul\Account\Models\Journal;
use Webkul\Account\Models\Move;
use Webkul\Account\Models\MoveLine;
use Webkul\Account\Models\Payment;
use Webkul\Account\Models\PaymentMethodLine;
use Webkul\Account\Services\MoveWorkflow;
use Webkul\Account\Services\Reconciler;
use Webkul\Branch\Database\Seeders\InternalAccountsSeeder;
use Webkul\Branch\Enums\TransferType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Models\BranchTransferSaleLine;
use Webkul\Branch\Models\InterBranchPayment;
use Webkul\Partner\Models\Partner;
use Webkul\Support\Models\Company;

/**
 * Inter-branch accounting (plan §5 + addendum A4).
 *
 * Every money/stock change is a posted Move; one DB transaction per
 * transition; internal amounts never touch the external 121000 /
 * 211000 accounts or the external INV / BILL journals.
 *
 * Engine deviations from the plan, found by reading the engine:
 *  - One ITR journal cannot serve all documents: the Move engine
 *    enforces SALE journals for out_invoice and PURCHASE for
 *    in_invoice (Move::computeJournalId). Three journals are used:
 *    ITR (general, ENTRY moves), ITRS (sale, out_invoices),
 *    ITRP (purchase, in_invoices).
 *  - A PRODUCT line's account is resolved from the product's
 *    income/expense accounts, overriding any explicit account_id.
 *    So invoice product lines carry no product_id (name / qty /
 *    price only) and an explicit internal account; the transfer
 *    document stays the product-level source of truth.
 *  - The standard Payment flow does not post to Bank (payment
 *    method lines carry no payment_account_id), so the two payment
 *    legs are explicit ENTRY Moves on the Bank journal, and the
 *    Payment records point at them.
 *  - The plan's §5.2(d) return entries are wrong as written (they
 *    would put stock up by P+C and never reverse revenue). The
 *    correct unwind reverses revenue and COGS at the stored P/C,
 *    expressed as first-class refund documents so the engine nets
 *    them against the originals: at return ship the Sub posts an
 *    in_refund (Dr 211500 P / Cr 110100 P) and Main reverses COGS
 *    (Dr 110100 C / Cr 500100 C); at return receive Main posts an
 *    out_refund (Dr 400100 P / Cr 121500 P). The refund payment-term
 *    lines reconcile against the parent invoices' lines.
 */
class InterBranchAccountingService
{
    public const RECEIVABLE_CODE = '121500';

    public const PAYABLE_CODE = '211500';

    public const SALES_CODE = '400100';

    public const COGS_CODE = '500100';

    public const STOCK_CODE = '110100';

    public const TRANSIT_CODE = '110150';

    public const BANK_CODE = '101401';

    public const RECEIVABLE_ACCOUNT_ID = 121500;

    public function __construct(
        protected MoveWorkflow $moveWorkflow,
        protected Reconciler $reconciler,
    ) {}

    // -----------------------------------------------------------------
    // Setup (idempotent; also called lazily before posting anything)
    // -----------------------------------------------------------------

    /**
     * Make sure the internal accounts, journals and branch partner
     * properties exist for this company. Cheap and idempotent, so it
     * is safe to call before every posting.
     *
     * @return array{accounts: array<string, Account>, journals: array<string, Journal>}
     */
    public function ensureSetup(int $companyId): array
    {
        $company = Company::withoutGlobalScopes()->findOrFail($companyId);

        $seeder = new InternalAccountsSeeder;
        $accounts = $seeder->seedAccounts($company);
        $seeder->seedJournals($company, $accounts);
        $seeder->linkBranchPartners($company, $accounts);

        // Plus the two pre-existing standard-chart accounts the
        // entries use: 110100 Stock Valuation and 101401 Bank.
        foreach ([self::STOCK_CODE, self::BANK_CODE] as $code) {
            $accounts[$code] = Account::withoutGlobalScopes()
                ->where('code', $code)
                ->firstOrFail();
        }

        $journals = Journal::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('code', ['ITR', 'ITRS', 'ITRP', 'BANK'])
            ->get()
            ->keyBy('code');

        return ['accounts' => $accounts, 'journals' => $journals];
    }

    /**
     * Point one branch Partner at the internal accounts (idempotent).
     */
    public function ensureBranchPartnerAccounts(Branch $branch): void
    {
        $setup = $this->ensureSetup($branch->company_id);

        $partner = $branch->partner;

        if (! $partner) {
            return;
        }

        $partner = \Webkul\Account\Models\Partner::withoutGlobalScopes()->find($partner->getKey()) ?? $partner;

        $partner->property_account_receivable_id = $setup['accounts'][self::RECEIVABLE_CODE]->getKey();
        $partner->property_account_payable_id = $setup['accounts'][self::PAYABLE_CODE]->getKey();
        $partner->save();
    }

    // -----------------------------------------------------------------
    // Ship: Entry 1 (out_invoice, P) + Entry 2 (COGS, C)
    // -----------------------------------------------------------------

    /**
     * Post the ship entries for a transfer (plan §5.2a + §5.2b).
     *
     * Entry 1 — out_invoice, Main, partner = Sub, at transfer price P:
     *   Dr 121500 P / Cr 400100 P
     * Entry 2 — ENTRY, Main, at FIFO cost C:
     *   Dr 500100 C / Cr 110100 C
     *
     * For a return transfer the direction is reversed (the returning
     * Sub is the seller): see postReturnShipEntries — an in_refund
     * on the Sub plus a COGS reversal on Main.
     *
     * Idempotent: a transfer whose out_invoice already exists is
     * skipped. Both entries post inside the caller's DB transaction.
     */
    public function postShipEntries(BranchTransferSale $transfer): void
    {
        $transfer->loadMissing(['sourceBranch', 'destinationBranch', 'lines.product']);

        $source = $transfer->sourceBranch;
        $destination = $transfer->destinationBranch;

        if (! $source || ! $destination) {
            throw new InvalidArgumentException('Transfer has no source or destination branch.');
        }

        $setup = $this->ensureSetup($transfer->company_id);
        $accounts = $setup['accounts'];
        $journals = $setup['journals'];

        $date = $transfer->transfer_date?->format('Y-m-d') ?? now()->format('Y-m-d');
        $dueDate = $transfer->due_date?->format('Y-m-d');

        if ($transfer->type === TransferType::Return) {
            $this->postReturnShipEntries($transfer, $setup, $date, $dueDate);

            return;
        }

        if ($transfer->out_invoice_id) {
            return;
        }

        $shippedLines = $transfer->lines->filter(fn (BranchTransferSaleLine $line) => (float) $line->quantity_shipped > 0);

        if ($shippedLines->isEmpty()) {
            return;
        }

        $totalC = $shippedLines->sum(fn (BranchTransferSaleLine $line) => round((float) $line->quantity_shipped * (float) $line->cost_price_unit, 4));

        // Entry 1: out_invoice at P — Main bills Sub.
        $outInvoice = $this->createInvoice(
            transfer: $transfer,
            moveType: MoveType::OUT_INVOICE,
            branch: $source,
            partner: $destination->partner,
            journal: $journals['ITRS'],
            lines: $shippedLines->map(fn (BranchTransferSaleLine $line) => [
                'name'       => $line->product?->name ?? "Product #{$line->product_id}",
                'quantity'   => (float) $line->quantity_shipped,
                'price_unit' => (float) $line->transfer_price_unit,
                'uom_id'     => $line->product?->uom_id,
                'account_id' => $accounts[self::SALES_CODE]->getKey(),
            ])->all(),
            date: $date,
            dueDate: $dueDate,
        );

        $transfer->forceFill(['out_invoice_id' => $outInvoice->getKey()])->save();

        // Entry 2: COGS at C — only for stock-holding transfers.
        if ($transfer->type !== TransferType::Service && $totalC > 0) {
            $cogs = $this->createEntry(
                transfer: $transfer,
                branch: $source,
                partner: $destination->partner,
                journal: $journals['ITR'],
                lines: [
                    ['account_id' => $accounts[self::COGS_CODE]->getKey(), 'debit' => $totalC, 'credit' => 0, 'name' => "COGS {$transfer->name}"],
                    ['account_id' => $accounts[self::STOCK_CODE]->getKey(), 'debit' => 0, 'credit' => $totalC, 'name' => "Stock {$transfer->name}"],
                ],
                date: $date,
                reference: "COGS {$transfer->name}",
            );

            $transfer->forceFill(['cogs_move_id' => $cogs->getKey()])->save();
        }

        $this->recomputeTransferPayment($transfer);
    }

    /**
     * Return ship entries (plan §5.2d, corrected — see class docblock).
     *
     * The returning Sub is the seller; Main is the buyer. Stock
     * leaves the Sub at the transfer price P, so the Sub's payable
     * falls by P; Main's COGS reverses at the stored FIFO cost C:
     *   Sub in_refund:  Dr 211500 P / Cr 110100 P
     *   Main ENTRY:     Dr 110100 C / Cr 500100 C
     * The in_refund's 211500 line reconciles against the parent
     * transfer's in_invoice 211500 line.
     */
    protected function postReturnShipEntries(BranchTransferSale $transfer, array $setup, string $date, ?string $dueDate): void
    {
        $accounts = $setup['accounts'];
        $journals = $setup['journals'];

        if ($transfer->in_invoice_id) {
            return;
        }

        $shippedLines = $transfer->lines->filter(fn (BranchTransferSaleLine $line) => (float) $line->quantity_shipped > 0);

        if ($shippedLines->isEmpty()) {
            return;
        }

        $transfer->loadMissing(['parentTransfer.lines']);

        $totalC = $shippedLines->sum(function (BranchTransferSaleLine $line) use ($transfer) {
            // The return line's own cost_price_unit is the Sub's layer
            // cost (= P). Main's COGS reversal must use the ORIGINAL
            // line's FIFO cost C, or Main would reverse more COGS
            // than it ever posted.
            $originalLine = $transfer->parentTransfer?->lines
                ->firstWhere('product_id', $line->product_id);

            $unitCost = $originalLine?->cost_price_unit ?? $line->cost_price_unit;

            return round((float) $line->quantity_shipped * (float) $unitCost, 4);
        });

        // Entry 1 (return ship): in_refund at P — the Sub's payable falls.
        $inRefund = $this->createInvoice(
            transfer: $transfer,
            moveType: MoveType::IN_REFUND,
            branch: $transfer->sourceBranch,
            partner: $transfer->destinationBranch->partner,
            journal: $journals['ITRP'],
            lines: $shippedLines->map(fn (BranchTransferSaleLine $line) => [
                'name'       => $line->product?->name ?? "Product #{$line->product_id}",
                'quantity'   => (float) $line->quantity_shipped,
                'price_unit' => (float) $line->transfer_price_unit,
                'uom_id'     => $line->product?->uom_id,
                'account_id' => $accounts[self::STOCK_CODE]->getKey(),
            ])->all(),
            date: $date,
            dueDate: $dueDate,
        );

        $transfer->forceFill(['in_invoice_id' => $inRefund->getKey()])->save();

        // Entry 2 (return ship): COGS reversal at C.
        if ($transfer->type !== TransferType::Service && $totalC > 0) {
            $cogs = $this->createEntry(
                transfer: $transfer,
                branch: $transfer->destinationBranch,
                partner: $transfer->sourceBranch->partner,
                journal: $journals['ITR'],
                lines: [
                    ['account_id' => $accounts[self::STOCK_CODE]->getKey(), 'debit' => $totalC, 'credit' => 0, 'name' => "Stock return {$transfer->name}"],
                    ['account_id' => $accounts[self::COGS_CODE]->getKey(), 'debit' => 0, 'credit' => $totalC, 'name' => "COGS reversal {$transfer->name}"],
                ],
                date: $date,
                reference: "COGS reversal {$transfer->name}",
            );

            $transfer->forceFill(['cogs_move_id' => $cogs->getKey()])->save();
        }

        $parent = $transfer->parentTransfer;

        if ($parent) {
            $parent->loadMissing('inInvoice');
            $this->reconcileRefundAgainstParent($inRefund, $parent->inInvoice, self::PAYABLE_CODE);
            $this->recomputeTransferPayment($parent);
        }
    }

    // -----------------------------------------------------------------
    // Receive: Entry 3 (in_invoice, P) per receipt
    // -----------------------------------------------------------------

    /**
     * Post the receipt entry for one receive event (plan §5.2b).
     *
     * Entry 3 — in_invoice, Sub, partner = Main, at P for the
     * quantities received in this call:
     *   Dr 110100 P / Cr 211500 P
     *
     * For a return transfer the received stock goes back to Main, so
     * the receipt entry is an out_refund on Main at P (Dr 400100 /
     * Cr 121500) that reverses the original revenue/receivable.
     *
     * @param  array<int, float>  $receivedQuantities  [line_id => quantity received in this call]
     */
    public function postReceiptEntries(BranchTransferSale $transfer, array $receivedQuantities): void
    {
        $transfer->loadMissing(['sourceBranch', 'destinationBranch', 'lines.product']);

        $receivedQuantities = array_filter($receivedQuantities, fn ($qty) => (float) $qty > 0);

        if ($receivedQuantities === []) {
            return;
        }

        $setup = $this->ensureSetup($transfer->company_id);
        $accounts = $setup['accounts'];
        $journals = $setup['journals'];

        $date = now()->format('Y-m-d');
        $dueDate = $transfer->due_date?->format('Y-m-d');

        $lines = $transfer->lines
            ->filter(fn (BranchTransferSaleLine $line) => isset($receivedQuantities[$line->getKey()]))
            ->map(fn (BranchTransferSaleLine $line) => [
                'name'       => $line->product?->name ?? "Product #{$line->product_id}",
                'quantity'   => (float) $receivedQuantities[$line->getKey()],
                'price_unit' => (float) $line->transfer_price_unit,
                'uom_id'     => $line->product?->uom_id,
                // Forward receipt: goods into stock (110100). Return
                // receipt: revenue reversal (400100) via out_refund.
                'account_id' => $transfer->type === TransferType::Return
                    ? $accounts[self::SALES_CODE]->getKey()
                    : $accounts[self::STOCK_CODE]->getKey(),
            ])->all();

        if ($transfer->type === TransferType::Return) {
            // Return receive: Main posts an out_refund at P, which
            // reverses the revenue and falls the receivable.
            //   Dr 400100 P / Cr 121500 P
            $invoice = $this->createInvoice(
                transfer: $transfer,
                moveType: MoveType::OUT_REFUND,
                branch: $transfer->destinationBranch,
                partner: $transfer->sourceBranch->partner,
                journal: $journals['ITRS'],
                lines: $lines,
                date: $date,
                dueDate: $dueDate,
            );

            $transfer->forceFill(['out_invoice_id' => $transfer->out_invoice_id ?? $invoice->getKey()])->save();

            $parent = $transfer->parentTransfer;

            if ($parent) {
                $parent->loadMissing('outInvoice');
                $this->reconcileRefundAgainstParent($invoice, $parent->outInvoice, self::RECEIVABLE_CODE);
                $this->recomputeTransferPayment($parent);
            }

            return;
        }

        $invoice = $this->createInvoice(
            transfer: $transfer,
            moveType: MoveType::IN_INVOICE,
            branch: $transfer->destinationBranch,
            partner: $transfer->sourceBranch->partner,
            journal: $journals['ITRP'],
            lines: $lines,
            date: $date,
            dueDate: $dueDate,
        );

        if (! $transfer->in_invoice_id) {
            $transfer->forceFill(['in_invoice_id' => $invoice->getKey()])->save();
        }

        $this->recomputeTransferPayment($transfer);
    }

    /**
     * Reconcile a refund's payment-term line against the parent
     * invoice's payment-term line on the same account (opposite
     * signs): e.g. return out_refund 121500 vs parent out_invoice
     * 121500, or return in_refund 211500 vs parent in_invoice
     * 211500.
     */
    protected function reconcileRefundAgainstParent(Move $refund, ?Move $parentInvoice, string $accountCode): void
    {
        if (! $parentInvoice) {
            return;
        }

        $this->reconcileMirrorLines($refund, $parentInvoice, $accountCode);
    }

    /**
     * Reconcile two payment-term lines on the same account (opposite
     * signs): e.g. out_invoice 121500 vs return out_invoice 121500.
     */
    protected function reconcileMirrorLines(?Move $first, ?Move $second, string $accountCode): void
    {
        if (! $first || ! $second) {
            return;
        }

        $account = Account::withoutGlobalScopes()->where('code', $accountCode)->first();

        if (! $account) {
            return;
        }

        $firstLine = $first->lines()->where('account_id', $account->getKey())
            ->where('display_type', DisplayType::PAYMENT_TERM)->first();

        $secondLine = $second->lines()->where('account_id', $account->getKey())
            ->where('display_type', DisplayType::PAYMENT_TERM)->first();

        if (! $firstLine || ! $secondLine) {
            return;
        }

        $firstLine->refresh();
        $secondLine->refresh();

        if ($firstLine->reconciled || $secondLine->reconciled) {
            return;
        }

        // Reconciler::reconcile calls ->load() on the collection, so
        // it must be an Eloquent collection, not a base one.
        $this->reconciler->reconcile($firstLine->newCollection([$firstLine, $secondLine]));
    }

    // -----------------------------------------------------------------
    // Cancel after ship: reverse everything to zero
    // -----------------------------------------------------------------

    /**
     * Reverse the ship entries of a transfer (cancel after ship).
     * Uses MoveWorkflow::reverse so the reversal Moves are proper
     * documents that reconcile against the originals, leaving every
     * residual at zero. The caller returns the in-transit stock.
     */
    public function reverseShipEntries(BranchTransferSale $transfer): void
    {
        $moves = collect();

        if ($transfer->out_invoice_id) {
            $moves->push(Move::find($transfer->out_invoice_id));
        }

        if ($transfer->in_invoice_id && $transfer->type === TransferType::Return) {
            $moves->push(Move::find($transfer->in_invoice_id));
        }

        if ($transfer->cogs_move_id) {
            $moves->push(Move::find($transfer->cogs_move_id));
        }

        $moves = $moves->filter()->filter(fn (Move $move) => $move->state === MoveState::POSTED);

        if ($moves->isEmpty()) {
            return;
        }

        $reversals = $this->moveWorkflow->reverse($moves, [], true);

        foreach ($reversals as $reversal) {
            $reversal->forceFill([
                'branch_id'               => $transfer->source_branch_id,
                'is_internal'             => true,
                'branch_transfer_sale_id' => $transfer->getKey(),
            ])->save();

            $reversal->lines()->update([
                'branch_id'               => $transfer->source_branch_id,
                'is_internal'             => true,
                'branch_transfer_sale_id' => $transfer->getKey(),
            ]);
        }

        $this->recomputeTransferPayment($transfer);
    }

    // -----------------------------------------------------------------
    // Payments (plan §5.2c)
    // -----------------------------------------------------------------

    /**
     * Record a payment from a Sub branch to Main.
     *
     * Creates the InterBranchPayment document, two linked Payment
     * legs (Sub outbound + Main inbound) with explicit ENTRY Moves
     * on the Bank journal, reconciles each allocation against the
     * transfer invoices, and recomputes residuals.
     *
     * @param  array{allocations: array<int, float>}  $data
     */
    public function recordPayment(Branch $payer, Branch $payee, float $amount, string $paymentDate, array $data = []): InterBranchPayment
    {
        $amount = round($amount, 4);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if ($payer->getKey() === $payee->getKey()) {
            throw new InvalidArgumentException('Payer and payee branches must differ.');
        }

        $allocations = $data['allocations'] ?? [];

        if ($allocations === []) {
            throw new InvalidArgumentException('At least one transfer allocation is required.');
        }

        $allocated = round(array_sum($allocations), 4);

        if (abs($allocated - $amount) > 0.0001) {
            throw new InvalidArgumentException("Allocations total {$allocated} but payment amount is {$amount}.");
        }

        $transfers = BranchTransferSale::query()
            ->whereIn('id', array_keys($allocations))
            ->with(['outInvoice', 'inInvoice'])
            ->get()
            ->keyBy('id');

        foreach ($allocations as $transferId => $allocation) {
            $transfer = $transfers->get($transferId);

            if (! $transfer) {
                throw new InvalidArgumentException("Transfer #{$transferId} not found.");
            }

            if ($transfer->destination_branch_id !== $payer->getKey() || $transfer->source_branch_id !== $payee->getKey()) {
                throw new InvalidArgumentException("Transfer {$transfer->name} is not a payable from {$payer->code} to {$payee->code}.");
            }

            if (! $transfer->outInvoice) {
                throw new InvalidArgumentException("Transfer {$transfer->name} has no posted out_invoice yet (ship it first).");
            }

            $residual = (float) $transfer->outInvoice->amount_residual;

            if (round((float) $allocation, 4) > round($residual, 4)) {
                throw new InvalidArgumentException("Allocation {$allocation} for {$transfer->name} exceeds its outstanding {$residual}.");
            }
        }

        $setup = $this->ensureSetup($payer->company_id);
        $accounts = $setup['accounts'];
        $journals = $setup['journals'];

        $bankJournal = $journals['BANK'] ?? Journal::withoutGlobalScopes()
            ->where('company_id', $payer->company_id)->where('code', 'BANK')->firstOrFail();

        $bankAccount = $accounts[self::BANK_CODE] ?? Account::withoutGlobalScopes()->where('code', self::BANK_CODE)->firstOrFail();

        // The Payment saving hook dereferences paymentMethodLine, so
        // both legs need the Bank journal's method line (its
        // payment_account_id is NULL — the ENTRY Moves we post are
        // what actually moves the money to Bank).
        $methodLineId = PaymentMethodLine::query()
            ->where('journal_id', $bankJournal->getKey())
            ->value('id');

        return DB::transaction(function () use ($payer, $payee, $amount, $paymentDate, $data, $allocations, $transfers, $accounts, $bankJournal, $bankAccount, $methodLineId) {
            $payment = InterBranchPayment::create([
                'company_id'      => $payer->company_id,
                'payer_branch_id' => $payer->getKey(),
                'payee_branch_id' => $payee->getKey(),
                'journal_id'      => $bankJournal->getKey(),
                'currency_id'     => $payer->company->currency_id,
                'reference'       => $data['reference'] ?? null,
                'memo'            => $data['memo'] ?? null,
                'payment_date'    => $paymentDate,
                'amount'          => $amount,
                'creator_id'      => $data['creator_id'] ?? auth()->id(),
            ]);

            foreach ($allocations as $transferId => $allocation) {
                $payment->allocations()->create([
                    'transfer_id' => $transferId,
                    'amount'      => round((float) $allocation, 4),
                ]);
            }

            // Sub leg: Dr 211500 (partner Main) / Cr Bank.
            $subMove = $this->createEntry(
                transfer: $transfers->first(),
                branch: $payer,
                partner: $payee->partner,
                journal: $bankJournal,
                lines: [
                    ['account_id' => $accounts[self::PAYABLE_CODE]->getKey(), 'debit' => $amount, 'credit' => 0, 'name' => "Inter-branch payment {$payment->id}"],
                    ['account_id' => $bankAccount->getKey(), 'debit' => 0, 'credit' => $amount, 'name' => "Inter-branch payment {$payment->id}"],
                ],
                date: $paymentDate,
                reference: $payment->reference ?? "IBP-{$payment->id}",
            );

            // Main leg: Dr Bank / Cr 121500 (partner Sub).
            $mainMove = $this->createEntry(
                transfer: $transfers->first(),
                branch: $payee,
                partner: $payer->partner,
                journal: $bankJournal,
                lines: [
                    ['account_id' => $bankAccount->getKey(), 'debit' => $amount, 'credit' => 0, 'name' => "Inter-branch payment {$payment->id}"],
                    ['account_id' => $accounts[self::RECEIVABLE_CODE]->getKey(), 'debit' => 0, 'credit' => $amount, 'name' => "Inter-branch payment {$payment->id}"],
                ],
                date: $paymentDate,
                reference: $payment->reference ?? "IBP-{$payment->id}",
            );

            // A payment spans one or more transfers, so its Moves do
            // not belong to a single transfer; the allocations table
            // is the linkage. Clear the transfer id stamped by
            // createEntry (which defaults it to the first transfer).
            foreach ([$subMove, $mainMove] as $paymentMove) {
                $paymentMove->forceFill(['branch_transfer_sale_id' => null])->save();
                $paymentMove->lines()->update(['branch_transfer_sale_id' => null]);
            }

            $subPayment = Payment::create([
                'company_id'             => $payer->company_id,
                'currency_id'            => $payer->company->currency_id,
                'journal_id'             => $bankJournal->getKey(),
                'partner_id'             => $payee->partner_id,
                'payment_type'           => PaymentType::SEND,
                'payment_method_line_id' => $methodLineId,
                'state'                  => PaymentStatus::DRAFT,
                'date'                   => $paymentDate,
                'amount'                 => $amount,
                'destination_account_id' => $accounts[self::PAYABLE_CODE]->getKey(),
                'outstanding_account_id' => $bankAccount->getKey(),
                'move_id'                => $subMove->getKey(),
                'memo'                   => $payment->memo,
            ]);

            $mainPayment = Payment::create([
                'company_id'             => $payee->company_id,
                'currency_id'            => $payee->company->currency_id,
                'journal_id'             => $bankJournal->getKey(),
                'partner_id'             => $payer->partner_id,
                'payment_type'           => PaymentType::RECEIVE,
                'payment_method_line_id' => $methodLineId,
                'state'                  => PaymentStatus::DRAFT,
                'date'                   => $paymentDate,
                'amount'                 => $amount,
                'destination_account_id' => $accounts[self::RECEIVABLE_CODE]->getKey(),
                'outstanding_account_id' => $bankAccount->getKey(),
                'move_id'                => $mainMove->getKey(),
                'memo'                   => $payment->memo,
            ]);

            $subPayment->forceFill(['paired_internal_transfer_payment_id' => $mainPayment->getKey()])->save();
            $mainPayment->forceFill(['paired_internal_transfer_payment_id' => $subPayment->getKey()])->save();

            $payment->forceFill([
                'sub_payment_id'  => $subPayment->getKey(),
                'main_payment_id' => $mainPayment->getKey(),
            ])->save();

            // Reconcile each allocation against the two invoices.
            foreach ($allocations as $transferId => $allocation) {
                $transfer = $transfers->get($transferId);

                $this->reconcilePaymentLeg(
                    $subMove,
                    $transfer->inInvoice,
                    self::PAYABLE_CODE,
                    (float) $allocation,
                );

                $this->reconcilePaymentLeg(
                    $mainMove,
                    $transfer->outInvoice,
                    self::RECEIVABLE_CODE,
                    (float) $allocation,
                );

                $this->recomputeTransferPayment($transfer);
            }

            // Mark both legs paid (the commercial settlement is done;
            // the bank lines reconcile later against the statement).
            foreach ([$subPayment, $mainPayment] as $leg) {
                $leg->forceFill(['state' => PaymentStatus::PAID])->save();
                $leg->refresh();
            }

            return $payment->refresh();
        });
    }

    /**
     * Reconcile one payment-leg line against one invoice's
     * payment-term line on the same account, for one allocation.
     */
    protected function reconcilePaymentLeg(Move $paymentMove, ?Move $invoice, string $accountCode, float $amount): void
    {
        if (! $invoice) {
            return;
        }

        $account = Account::withoutGlobalScopes()->where('code', $accountCode)->first();

        if (! $account) {
            return;
        }

        $paymentLine = $paymentMove->lines()->where('account_id', $account->getKey())->first();
        $invoiceLine = $invoice->lines()->where('account_id', $account->getKey())
            ->where('display_type', DisplayType::PAYMENT_TERM)->first();

        if (! $paymentLine || ! $invoiceLine) {
            throw new InvalidArgumentException("Cannot reconcile: missing {$accountCode} line.");
        }

        $paymentLine->refresh();
        $invoiceLine->refresh();

        $this->reconciler->reconcile($paymentLine->newCollection([$paymentLine, $invoiceLine]));

        $invoice->refresh();
        $invoice->computePaymentState();
        $invoice->save();
    }

    /**
     * Recompute amount_paid / amount_residual on a transfer from its
     * out_invoice (the commercial truth), and sync payment_state.
     * Return transfers are skipped: their out_invoice_id holds a
     * credit note and their commercial truth lives on the parent.
     */
    public function recomputeTransferPayment(BranchTransferSale $transfer): void
    {
        if ($transfer->type === TransferType::Return) {
            return;
        }

        $transfer->loadMissing('outInvoice');

        if (! $transfer->outInvoice) {
            return;
        }

        $invoice = $transfer->outInvoice->fresh();

        $paymentState = $invoice->payment_state;

        $transfer->forceFill([
            'amount_paid'     => round((float) $invoice->amount_total - (float) $invoice->amount_residual, 4),
            'amount_residual' => round((float) $invoice->amount_residual, 4),
            'payment_state'   => $paymentState instanceof \BackedEnum ? $paymentState->value : (string) $paymentState,
        ])->save();
    }

    // -----------------------------------------------------------------
    // Low-level builders (Move boot logic is never bypassed)
    // -----------------------------------------------------------------

    /**
     * Create + post an invoice (out_invoice or in_invoice).
     *
     * Product lines carry no product_id: the engine resolves a
     * PRODUCT line's account from the product, overriding any
     * explicit account_id, so the explicit internal account only
     * sticks when the line has no product. The transfer document
     * stays the product-level source of truth.
     *
     * @param  array<int, array{name: string, quantity: float, price_unit: float, uom_id: int|null, account_id: int}>  $lines
     */
    protected function createInvoice(BranchTransferSale $transfer, MoveType $moveType, Branch $branch, ?Partner $partner, Journal $journal, array $lines, string $date, ?string $dueDate): Move
    {
        $company = $branch->company;

        $move = Move::create([
            'journal_id'       => $journal->getKey(),
            'company_id'       => $branch->company_id,
            'currency_id'      => $company->currency_id,
            'state'            => MoveState::DRAFT,
            'move_type'        => $moveType->value,
            'date'             => $date,
            'invoice_date'     => $date,
            'invoice_date_due' => $dueDate,
            'partner_id'       => $partner?->getKey(),
            'reference'        => $transfer->name,
            'invoice_origin'   => $transfer->name,
        ]);

        $move->forceFill([
            'branch_id'               => $branch->getKey(),
            'is_internal'             => true,
            'branch_transfer_sale_id' => $transfer->getKey(),
        ])->save();

        foreach ($lines as $line) {
            MoveLine::create([
                'move_id'      => $move->getKey(),
                'company_id'   => $branch->company_id,
                'currency_id'  => $company->currency_id,
                'partner_id'   => $partner?->getKey(),
                'account_id'   => $line['account_id'],
                'journal_id'   => $journal->getKey(),
                'display_type' => DisplayType::PRODUCT,
                'name'         => $line['name'],
                'quantity'     => $line['quantity'],
                'price_unit'   => $line['price_unit'],
                'uom_id'       => $line['uom_id'],
                'date'         => $date,
            ]);
        }

        $posted = $this->moveWorkflow->post($move);

        $posted->lines()->update([
            'branch_id'               => $branch->getKey(),
            'is_internal'             => true,
            'branch_transfer_sale_id' => $transfer->getKey(),
        ]);

        return $posted->refresh();
    }

    /**
     * Create + post a general ENTRY Move (COGS, payment legs).
     *
     * @param  array<int, array{account_id: int, debit: float, credit: float, name: string}>  $lines
     */
    protected function createEntry(BranchTransferSale $transfer, Branch $branch, ?Partner $partner, Journal $journal, array $lines, string $date, string $reference): Move
    {
        $company = $branch->company;

        $move = Move::create([
            'journal_id'  => $journal->getKey(),
            'company_id'  => $branch->company_id,
            'currency_id' => $company->currency_id,
            'state'       => MoveState::DRAFT,
            'move_type'   => MoveType::ENTRY->value,
            'date'        => $date,
            'partner_id'  => $partner?->getKey(),
            'reference'   => $reference,
        ]);

        $move->forceFill([
            'branch_id'               => $branch->getKey(),
            'is_internal'             => true,
            'branch_transfer_sale_id' => $transfer->getKey(),
        ])->save();

        foreach ($lines as $line) {
            $debit = round((float) $line['debit'], 4);
            $credit = round((float) $line['credit'], 4);

            // display_type must be set explicitly: the saving hooks
            // run computeAccountId BEFORE computeDisplayType, so a
            // line without one falls into the default case and gets
            // the journal's default account, discarding the explicit
            // account_id. PRODUCT keeps the explicit account.
            MoveLine::create([
                'move_id'                  => $move->getKey(),
                'company_id'               => $branch->company_id,
                'currency_id'              => $company->currency_id,
                'partner_id'               => $partner?->getKey(),
                'account_id'               => $line['account_id'],
                'journal_id'               => $journal->getKey(),
                'display_type'             => DisplayType::PRODUCT,
                'name'                     => $line['name'],
                'debit'                    => $debit,
                'credit'                   => $credit,
                'balance'                  => round($debit - $credit, 4),
                'amount_currency'          => round($debit - $credit, 4),
                'amount_residual'          => round($debit - $credit, 4),
                'amount_residual_currency' => round($debit - $credit, 4),
                'date'                     => $date,
            ]);
        }

        $posted = $this->moveWorkflow->post($move);

        $posted->lines()->update([
            'branch_id'               => $branch->getKey(),
            'is_internal'             => true,
            'branch_transfer_sale_id' => $transfer->getKey(),
        ]);

        return $posted->refresh();
    }
}
