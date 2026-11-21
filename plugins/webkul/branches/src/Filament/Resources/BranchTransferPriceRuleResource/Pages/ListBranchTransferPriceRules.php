<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource;

class ListBranchTransferPriceRules extends ListRecords
{
    protected static string $resource = BranchTransferPriceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
