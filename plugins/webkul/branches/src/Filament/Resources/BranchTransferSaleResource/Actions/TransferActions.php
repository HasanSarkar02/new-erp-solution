<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Services\BranchTransferService;

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
            static::cancelAction(),
            static::returnAction(),
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

                Notification::make()->title('Transfer shipped. In Phase 2 this is a status change only — no stock moves yet.')->success()->send();
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

                Notification::make()->title('Receipt recorded. In Phase 2 this is a status change only — no stock moves yet.')->success()->send();
            });
    }

    protected static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->visible(fn (BranchTransferSale $record) => in_array($record->status, [TransferStatus::Draft, TransferStatus::Confirmed], true))
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
}
