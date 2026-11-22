<?php

namespace Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource;

class ListBranchStockWriteOffs extends ListRecords
{
    protected static string $resource = BranchStockWriteOffResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
