<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Branch\Services\BranchWriteOffService;
use Webkul\Branch\Services\InterBranchAccountingService;

/**
 * Header actions for the transfer view page (plan §7.1, §8.2).
 *
 * Each action calls one BranchTransferService transition — the Filament
 * layer never changes status itself. Ship / receive / return take
 * per-line quantities in a modal; everything else ships/receives the
 * full quantity by default.
 */
class TransferActions
{
    /** @return Action[] */
    public static function headerActions(): array
    {
        return [
            static::approveAction(),
            static::confirmAction(),
            static::shipAction(),
            static::receiveAction(),
            static::closeShortAction(),
            static::recordPaymentAction(),
            static::cancelAction(),
            static::returnAction(),
            static::returnDamagedAction(),
            static::reportLostAction(),
        ];
    }

    protected static function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Approve (Main)')
            ->icon('heroicon-o-check-badge')
            ->color('warning')
            ->visible(fn (BranchTransferSale $record) => $record->requiresMainApproval() && ! $record->isApproved())
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('approve', $record))
            ->requiresConfirmation()
            ->action(function (BranchTransferSale $record) {
                app(BranchTransferService::class)->approve($record);

                Notification::make()->title('Transfer approved by Main.')->success()->send();
            });
    }

    protected static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirm')
            ->icon('heroicon-o-check')
            ->color('success')
            ->visible(fn (BranchTransferSale $record) => $record->status === TransferStatus::Draft)
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('confirm', $record))
            ->requiresConfirmation()
            ->action(function (BranchTransferSale $record) {
                app(BranchTransferService::class)->confirm($record);

                Notification::make()->title('Transfer confirmed.')->success()->send();
            });
    }

    protected static function shipAction(): Action
    {
        return Action::make('ship')
            ->label('Ship')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->visible(fn (BranchTransferSale $record) => $record->status === TransferStatus::Confirmed)
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('ship', $record))
            ->form(fn (BranchTransferSale $record) => [
                Repeater::make('ship_lines')
                    ->label('Shipped quantities')
                    ->schema([
                        Select::make('line_id')
                            ->label('Line')
                            ->options($record->lines->mapWithKeys(fn ($line) => [
                                $line->getKey() => $line->product->name.' — ordered '.$line->quantity,
                            ])->all())
                            ->required()
                            ->disabled()
                            ->dehydrated(true),
                        TextInput::make('quantity')
                            ->label('Shipped qty')
                            ->numeric()
                            ->required()
                            ->minValue(0.0001),
                    ])
                    ->columns(2)
                    ->default($record->lines->map(fn ($line) => [
                        'line_id'  => $line->getKey(),
                        'quantity' => (float) $line->quantity,
                    ])->all())
                    ->reorderable(false),
            ])
            ->action(function (BranchTransferSale $record, array $data) {
                $quantities = collect($data['ship_lines'] ?? [])
                    ->mapWithKeys(fn (array $row) => [(int) $row['line_id'] => (float) $row['quantity']])
                    ->all();

                app(BranchTransferService::class)->ship($record, null, $quantities);

                Notification::make()->title('Transfer shipped. Stock moved to transit and the invoices + COGS were posted.')->success()->send();
            });
    }

    /**
     * Record a payment from the destination branch to the source
     * branch (Phase 4, plan §5.2c). Main records the payment even
     * when the money was handed over at a Sub branch.
     */
    protected static function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Record payment')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (BranchTransferSale $record) => $record->out_invoice_id !== null && (float) $record->amount_residual > 0)
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('recordPayment', $record))
            ->form(fn (BranchTransferSale $record) => [
                TextInput::make('amount')
                    ->label('Amount (BDT)')
                    ->numeric()
                    ->required()
                    ->minValue(0.0001)
                    ->maxValue((float) $record->amount_residual)
                    ->default((float) $record->amount_residual),
                DatePicker::make('payment_date')
                    ->label('Payment date')
                    ->required()
                    ->default(now()->toDateString()),
                TextInput::make('reference')
                    ->label('Reference')
                    ->maxLength(255),
                Textarea::make('memo')
                    ->label('Memo')
                    ->rows(2),
            ])
            ->action(function (BranchTransferSale $record, array $data) {
                $record->loadMissing(['sourceBranch', 'destinationBranch']);

                app(InterBranchAccountingService::class)->recordPayment(
                    $record->destinationBranch,
                    $record->sourceBranch,
                    (float) $data['amount'],
                    $data['payment_date'],
                    [
                        'reference'   => $data['reference'] ?? null,
                        'memo'        => $data['memo'] ?? null,
                        'allocations' => [$record->getKey() => (float) $data['amount']],
                    ],
                );

                Notification::make()->title('Payment recorded and reconciled against the transfer invoices.')->success()->send();
            });
    }

    protected static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label('Receive')
            ->icon('heroicon-o-inbox-arrow-down')
            ->color('success')
            ->visible(fn (BranchTransferSale $record) => in_array($record->status, [TransferStatus::InTransit, TransferStatus::PartiallyReceived], true))
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('receive', $record))
            ->form(fn (BranchTransferSale $record) => [
                Repeater::make('receive_lines')
                    ->label('Received quantities')
                    ->schema([
                        Select::make('line_id')
                            ->label('Line')
                            ->options($record->lines->mapWithKeys(fn ($line) => [
                                $line->getKey() => $line->product->name.' — shipped '.$line->quantity_shipped,
                            ])->all())
                            ->required()
                            ->disabled()
                            ->dehydrated(true),
                        TextInput::make('quantity')
                            ->label('Received qty')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->columns(2)
                    ->default($record->lines->map(fn ($line) => [
                        'line_id'  => $line->getKey(),
                        'quantity' => (float) $line->quantity_shipped - (float) $line->quantity_received,
                    ])->all())
                    ->reorderable(false),
            ])
            ->action(function (BranchTransferSale $record, array $data) {
                $quantities = collect($data['receive_lines'] ?? [])
                    ->mapWithKeys(fn (array $row) => [(int) $row['line_id'] => (float) $row['quantity']])
                    ->all();

                app(BranchTransferService::class)->receive($record, null, $quantities);

                Notification::make()->title('Receipt recorded. Stock moved in and the payable invoice was posted.')->success()->send();
            });
    }

    /**
     * Close the unshipped remainder (Phase 5). Only offered while
     * some ordered units were never shipped.
     */
    protected static function closeShortAction(): Action
    {
        return Action::make('closeShort')
            ->label('Close short')
            ->icon('heroicon-o-scissors')
            ->color('gray')
            ->visible(fn (BranchTransferSale $record) => in_array($record->status, [TransferStatus::InTransit, TransferStatus::PartiallyReceived], true)
                && ! $record->is_closed_short
                && $record->lines->contains(fn ($line) => (float) $line->quantity > (float) $line->quantity_shipped))
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('closeShort', $record))
            ->form([
                Textarea::make('note')->label('Note (recorded in history)')->rows(2),
            ])
            ->requiresConfirmation()
            ->action(function (BranchTransferSale $record, array $data) {
                app(BranchTransferService::class)->closeShort($record, null, $data['note'] ?? null);

                Notification::make()->title('Unshipped remainder closed. The shipped part still needs to be received.')->success()->send();
            });
    }

    protected static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (BranchTransferSale $record) => in_array($record->status, [TransferStatus::Draft, TransferStatus::Confirmed, TransferStatus::InTransit], true))
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('cancel', $record))
            ->form([
                Textarea::make('note')->label('Reason')->rows(2),
            ])
            ->action(function (BranchTransferSale $record, array $data) {
                app(BranchTransferService::class)->cancel($record, null, $data['note'] ?? null);

                Notification::make()->title('Transfer cancelled.')->success()->send();
            });
    }

    protected static function returnAction(): Action
    {
        return Action::make('return')
            ->label('Create return')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (BranchTransferSale $record) => in_array($record->status, [TransferStatus::Received, TransferStatus::PartiallyReceived], true))
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('return', $record))
            ->form(fn (BranchTransferSale $record) => [
                Repeater::make('return_lines')
                    ->label('Return quantities (at the original transfer price)')
                    ->schema([
                        Select::make('line_id')
                            ->label('Line')
                            ->options($record->lines->mapWithKeys(fn ($line) => [
                                $line->getKey() => $line->product->name.' — received '.$line->quantity_received,
                            ])->all())
                            ->required()
                            ->disabled()
                            ->dehydrated(true),
                        TextInput::make('quantity')
                            ->label('Return qty')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->columns(2)
                    ->default($record->lines->map(fn ($line) => [
                        'line_id'  => $line->getKey(),
                        'quantity' => (float) $line->quantity_received - (float) $line->quantity_returned,
                    ])->all())
                    ->reorderable(false),
            ])
            ->action(function (BranchTransferSale $record, array $data) {
                $quantities = collect($data['return_lines'] ?? [])
                    ->mapWithKeys(fn (array $row) => [(int) $row['line_id'] => (float) $row['quantity']])
                    ->all();

                app(BranchTransferService::class)->return($record, null, $quantities);

                Notification::make()->title('Return transfer created (draft).')->success()->send();
            });
    }

    /**
     * Return DAMAGED goods (Phase 5, addendum A3): the Sub's payable
     * falls only by the agreed credit value per unit; the difference
     * is the Sub's Inventory Damage Loss. The goods go to Main's
     * Scrap location on receipt.
     */
    protected static function returnDamagedAction(): Action
    {
        return Action::make('returnDamaged')
            ->label('Return damaged')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->visible(fn (BranchTransferSale $record) => in_array($record->status, [TransferStatus::Received, TransferStatus::PartiallyReceived], true))
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('returnDamaged', $record))
            ->form(fn (BranchTransferSale $record) => [
                Repeater::make('return_lines')
                    ->label('Damaged return — agreed credit per unit (0 = no credit, Sub bears the full loss)')
                    ->schema([
                        Select::make('line_id')
                            ->label('Line')
                            ->options($record->lines->mapWithKeys(fn ($line) => [
                                $line->getKey() => $line->product->name.' — received '.$line->quantity_received.' @ '.$line->transfer_price_unit,
                            ])->all())
                            ->required()
                            ->disabled()
                            ->dehydrated(true),
                        TextInput::make('quantity')
                            ->label('Return qty')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                        TextInput::make('credit_value_unit')
                            ->label('Agreed credit / unit')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->columns(3)
                    ->default($record->lines->map(fn ($line) => [
                        'line_id'           => $line->getKey(),
                        'quantity'          => (float) $line->quantity_received - (float) $line->quantity_returned,
                        'credit_value_unit' => (float) $line->transfer_price_unit,
                    ])->all())
                    ->reorderable(false),
            ])
            ->action(function (BranchTransferSale $record, array $data) {
                $quantities = collect($data['return_lines'] ?? [])
                    ->mapWithKeys(fn (array $row) => [(int) $row['line_id'] => (float) $row['quantity']])
                    ->all();

                $credits = collect($data['return_lines'] ?? [])
                    ->mapWithKeys(fn (array $row) => [(int) $row['line_id'] => (float) $row['credit_value_unit']])
                    ->all();

                app(BranchTransferService::class)->returnDamaged($record, null, $quantities, $credits);

                Notification::make()->title('Damaged return transfer created (draft). Goods go to Scrap on receipt.')->success()->send();
            });
    }

    /**
     * Report lost / missing stock (Phase 5, addendum A3): creates a
     * draft write-off against this transfer. Main approves, posts
     * and (optionally) forgives it from the write-off list.
     */
    protected static function reportLostAction(): Action
    {
        return Action::make('reportLost')
            ->label('Report lost stock')
            ->icon('heroicon-o-magnifying-glass')
            ->color('danger')
            ->visible(fn (BranchTransferSale $record) => in_array($record->status, [TransferStatus::Received, TransferStatus::PartiallyReceived], true))
            ->authorize(fn (BranchTransferSale $record) => Gate::allows('reportLost', $record))
            ->form(fn (BranchTransferSale $record) => [
                Repeater::make('lost_lines')
                    ->label('Lost / missing quantities')
                    ->schema([
                        Select::make('line_id')
                            ->label('Line')
                            ->options($record->lines->mapWithKeys(fn ($line) => [
                                $line->getKey() => $line->product->name.' — received '.$line->quantity_received,
                            ])->all())
                            ->required()
                            ->disabled()
                            ->dehydrated(true),
                        TextInput::make('quantity')
                            ->label('Lost qty')
                            ->numeric()
                            ->required()
                            ->minValue(0),
                    ])
                    ->columns(2)
                    ->default($record->lines->map(fn ($line) => [
                        'line_id'  => $line->getKey(),
                        'quantity' => 0,
                    ])->all())
                    ->reorderable(false),
                Textarea::make('investigation_note')
                    ->label('Investigation note — what happened, when, who checked')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (BranchTransferSale $record, array $data) {
                $lines = collect($data['lost_lines'] ?? [])
                    ->filter(fn (array $row) => (float) $row['quantity'] > 0)
                    ->map(fn (array $row) => [
                        'product_id' => (int) $record->lines->firstWhere('id', (int) $row['line_id'])->product_id,
                        'quantity'   => (float) $row['quantity'],
                    ])
                    ->values()
                    ->all();

                $writeOff = app(BranchWriteOffService::class)->create(
                    $record->destinationBranch,
                    $record,
                    $lines,
                    $data['investigation_note'],
                );

                Notification::make()->title("Write-off {$writeOff->name} created (draft). Main must approve and post it.")->success()->send();
            });
    }
}
