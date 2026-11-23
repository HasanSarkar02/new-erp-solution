<?php

namespace Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;
use Webkul\Branch\Enums\WriteOffStatus;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource;
use Webkul\Branch\Models\BranchStockWriteOff;
use Webkul\Branch\Services\BranchWriteOffService;

/**
 * Write-off view: Main approves, posts and forgives from here.
 * Posting moves the stock to Scrap and posts Dr 645000 / Cr
 * 110100; forgiveness falls the receivable and the payable.
 */
class ViewBranchStockWriteOff extends ViewRecord
{
    protected static string $resource = BranchStockWriteOffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve (Main)')
                ->icon('heroicon-o-check-badge')
                ->color('warning')
                ->visible(fn (BranchStockWriteOff $record) => $record->status === WriteOffStatus::Draft)
                ->authorize(fn (BranchStockWriteOff $record) => Gate::allows('approve', $record))
                ->requiresConfirmation()
                ->action(function (BranchStockWriteOff $record) {
                    app(BranchWriteOffService::class)->approve($record, auth()->user());

                    Notification::make()->title('Write-off approved.')->success()->send();
                }),

            Action::make('post')
                ->label('Post write-off')
                ->icon('heroicon-o-check')
                ->color('success')
                ->visible(fn (BranchStockWriteOff $record) => $record->status === WriteOffStatus::Approved)
                ->authorize(fn (BranchStockWriteOff $record) => Gate::allows('post', $record))
                ->requiresConfirmation()
                ->action(function (BranchStockWriteOff $record) {
                    app(BranchWriteOffService::class)->post($record, auth()->user());

                    Notification::make()->title('Write-off posted. Stock scrapped and the loss is on the ledger. The branch still owes Main.')->success()->send();
                }),

            Action::make('forgive')
                ->label('Forgive debt (Main)')
                ->icon('heroicon-o-heart')
                ->color('danger')
                ->visible(fn (BranchStockWriteOff $record) => $record->status === WriteOffStatus::Posted)
                ->authorize(fn (BranchStockWriteOff $record) => Gate::allows('forgive', $record))
                ->requiresConfirmation()
                ->action(function (BranchStockWriteOff $record) {
                    app(BranchWriteOffService::class)->forgive($record, auth()->user());

                    Notification::make()->title('Debt forgiven. Main absorbs the loss; the branch no longer owes it.')->success()->send();
                }),
        ];
    }
}
