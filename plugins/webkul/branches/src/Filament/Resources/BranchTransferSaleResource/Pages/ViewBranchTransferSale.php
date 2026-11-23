<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Actions\TransferActions;

class ViewBranchTransferSale extends ViewRecord
{
    protected static string $resource = BranchTransferSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...TransferActions::headerActions(),
            EditAction::make(),
        ];
    }
}
