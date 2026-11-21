<?php

namespace Webkul\Branch\Filament\Resources\BranchResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Webkul\Branch\Filament\Resources\BranchResource;
use Webkul\Branch\Services\BranchService;

/**
 * Branch creation goes through BranchService (plan §4.5) so the
 * Partner, Warehouse and manager access are created together with
 * the branch, inside one transaction.
 */
class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordCreation(array $data): Model
    {
        return app(BranchService::class)->create($data, Auth::user());
    }
}
