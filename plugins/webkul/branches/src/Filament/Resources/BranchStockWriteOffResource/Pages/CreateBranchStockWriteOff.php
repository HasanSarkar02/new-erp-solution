<?php

namespace Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;
use Webkul\Branch\Services\BranchWriteOffService;

/**
 * Create page: the write-off is built through
 * BranchWriteOffService, never by direct model fill — the service
 * validates the quantities against the transfer and prices every
 * line at the transfer price P.
 */
class CreateBranchStockWriteOff extends CreateRecord
{
    protected static string $resource = BranchStockWriteOffResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $branch = Branch::withoutGlobalScopes()->findOrFail((int) $data['branch_id']);
        $transfer = BranchTransferSale::query()->findOrFail((int) $data['transfer_id']);

        $lines = collect($data['lines'] ?? [])
            ->map(fn (array $row) => [
                'product_id' => (int) $row['product_id'],
                'quantity'   => (float) $row['quantity'],
            ])
            ->all();

        return app(BranchWriteOffService::class)->create(
            $branch,
            $transfer,
            $lines,
            $data['investigation_note'],
            auth()->user(),
        );
    }
}
