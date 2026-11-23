<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource;
use Webkul\Support\Models\Company;

class CreateBranchTransferPriceRule extends CreateRecord
{
    protected static string $resource = BranchTransferPriceRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['company_id'] ??= current_company_id() ?? Company::query()->orderBy('id')->value('id');
        $data['creator_id'] = auth()->id();

        return $data;
    }
}
