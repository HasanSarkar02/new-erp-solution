<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSaleHistory;
use Webkul\Branch\Services\BranchTransferPriceService;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Product\Models\Product;
use Webkul\Support\Models\Company;

/**
 * Creating a transfer goes through the same validation as the service
 * (validateCreation), and the model numbers the document on create.
 * Line prices left empty in the form are filled from the price rules
 * here, so a saved line always has an explicit price.
 */
class CreateBranchTransferSale extends CreateRecord
{
    protected static string $resource = BranchTransferSaleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        app(BranchTransferService::class)->validateCreation($data);

        $companyId = (int) ($data['company_id'] ?? current_company_id() ?? Company::query()->orderBy('id')->value('id'));
        $company = Company::query()->findOrFail($companyId);

        $transferDate = isset($data['transfer_date']) ? Carbon::parse($data['transfer_date']) : now();
        $termsDays = (int) ($data['payment_terms_days'] ?? BranchTransferService::DEFAULT_PAYMENT_TERMS_DAYS);

        $data['company_id'] = $companyId;
        $data['currency_id'] = $company->currency_id;
        $data['status'] = TransferStatus::Draft->value;
        $data['due_date'] = $transferDate->copy()->addDays($termsDays)->toDateString();
        $data['creator_id'] = auth()->id();
        $data['created_by_branch_id'] = $data['source_branch_id'] ?? null;

        foreach ($data['lines'] as $index => $line) {
            $product = Product::query()->find((int) ($line['product_id'] ?? 0));

            if ($product) {
                $data['lines'][$index]['uom_id'] ??= $product->uom_id;

                if (empty($line['transfer_price_unit'])) {
                    $destination = Branch::withoutGlobalScopes()->find((int) ($data['destination_branch_id'] ?? 0));

                    $data['lines'][$index]['transfer_price_unit'] = app(BranchTransferPriceService::class)
                        ->suggestPrice($product, $destination, $transferDate);
                }

                $data['lines'][$index]['price_subtotal'] = (float) ($line['quantity'] ?? 0)
                    * (float) $data['lines'][$index]['transfer_price_unit'];
            }
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->refresh();
        $this->record->recomputeTotals();

        BranchTransferSaleHistory::create([
            'transfer_id' => $this->record->getKey(),
            'user_id'     => auth()->id(),
            'from_status' => null,
            'to_status'   => TransferStatus::Draft->value,
            'note'        => 'Transfer created as draft.',
        ]);
    }
}
