<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource;
use Webkul\Branch\Services\BranchTransferService;

/**
 * Only drafts are editable (enforced by the resource + policy).
 * Saving re-validates the lines and refreshes the totals.
 */
class EditBranchTransferSale extends EditRecord
{
    protected static string $resource = BranchTransferSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->status !== TransferStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only a draft transfer can be edited.',
            ]);
        }

        app(BranchTransferService::class)->validateCreation($data);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->refresh();
        $this->record->recomputeTotals();
    }
}
