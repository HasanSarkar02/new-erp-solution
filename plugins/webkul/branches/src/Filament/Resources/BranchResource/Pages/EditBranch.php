<?php

namespace Webkul\Branch\Filament\Resources\BranchResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Filament\Resources\BranchResource;
use Webkul\Branch\Models\Branch;
use Webkul\Inventory\Models\Warehouse;

/**
 * Branch edits keep the one-Main rule and the warehouse link intact.
 *
 * `is_main` is derived from `type` (a branch is Main exactly when its
 * type is Main). Changing the warehouse re-links it: the old warehouse
 * is unlinked and the new one is linked to this branch.
 */
class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['is_main'] = ($data['type'] ?? $this->record->type->value) === BranchType::Main->value;

        if ($data['is_main']) {
            $otherMain = Branch::withoutGlobalScopes()
                ->where('company_id', $data['company_id'] ?? $this->record->company_id)
                ->where('type', BranchType::Main->value)
                ->where('is_main', true)
                ->where('id', '!=', $this->record->getKey())
                ->exists();

            if ($otherMain) {
                throw ValidationException::withMessages([
                    'data.type' => 'This company already has a Main branch. Only one Main branch is allowed per company.',
                ]);
            }
        }

        if (($data['type'] ?? null) === BranchType::Service->value) {
            $data['warehouse_id'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $oldWarehouseId = $this->record->getOriginal('warehouse_id');
        $newWarehouseId = $this->record->warehouse_id;

        if ($oldWarehouseId && $oldWarehouseId !== $newWarehouseId) {
            Warehouse::withoutGlobalScopes()
                ->whereKey($oldWarehouseId)
                ->update(['branch_id' => null]);
        }

        if ($newWarehouseId) {
            Warehouse::withoutGlobalScopes()
                ->whereKey($newWarehouseId)
                ->update(['branch_id' => $this->record->getKey()]);
        }
    }
}
