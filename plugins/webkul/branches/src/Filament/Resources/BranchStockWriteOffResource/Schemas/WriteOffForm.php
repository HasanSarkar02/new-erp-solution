<?php

namespace Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Models\BranchTransferSale;

/**
 * Write-off create form (Phase 5).
 *
 * The branch and the received transfer pick the goods; the unit
 * value is always the transfer price P (set by the service, not
 * the form). The investigation note is required — a write-off
 * without an explanation is not acceptable.
 */
class WriteOffForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch_id')
                ->label('Branch (where the stock went missing)')
                ->options(fn () => Branch::query()
                    ->where('type', BranchType::Sub)
                    ->where('is_active', true)
                    ->orderBy('code')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->reactive(),

            Select::make('transfer_id')
                ->label('Transfer the goods came on')
                ->options(fn (Get $get) => BranchTransferSale::query()
                    ->where('destination_branch_id', $get('branch_id'))
                    ->whereIn('status', [TransferStatus::Received, TransferStatus::PartiallyReceived])
                    ->orderByDesc('transfer_date')
                    ->pluck('name', 'id')
                    ->all())
                ->required()
                ->reactive(),

            Repeater::make('lines')
                ->label('Lost / missing lines')
                ->schema([
                    Select::make('product_id')
                        ->label('Product')
                        ->options(function (Get $get) {
                            $transfer = BranchTransferSale::query()->find($get('../../transfer_id'));

                            if (! $transfer) {
                                return [];
                            }

                            return $transfer->lines
                                ->mapWithKeys(fn ($line) => [
                                    $line->product_id => $line->product->name.' — received '.$line->quantity_received.' @ '.$line->transfer_price_unit,
                                ])
                                ->all();
                        })
                        ->required()
                        ->reactive(),
                    TextInput::make('quantity')
                        ->label('Lost qty')
                        ->numeric()
                        ->required()
                        ->minValue(0.0001),
                ])
                ->columns(2)
                ->minItems(1)
                ->required(),

            Textarea::make('investigation_note')
                ->label('Investigation note — what happened, when, who checked')
                ->required()
                ->rows(3),
        ]);
    }
}
