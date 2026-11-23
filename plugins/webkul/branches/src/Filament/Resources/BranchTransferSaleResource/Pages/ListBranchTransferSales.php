<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource;

class ListBranchTransferSales extends ListRecords
{
    protected static string $resource = BranchTransferSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
