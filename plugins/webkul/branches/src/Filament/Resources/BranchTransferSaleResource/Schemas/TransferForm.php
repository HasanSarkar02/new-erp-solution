<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Webkul\Branch\Enums\TransferType;
use Webkul\Branch\Models\Branch;
use Webkul\Branch\Services\BranchTransferPriceService;
use Webkul\Branch\Services\BranchTransferService;
use Webkul\Product\Models\Product;

/**
 * Internal Transfer Sale form (plan §8.2).
 *
 * Lines: variant-aware product select (configurable parents excluded),
 * qty, available qty at the source warehouse (read-only), transfer
 * price suggested from the price rules (editable override), line total.
 * Validation of branches / lines / prices is shared with the service
 * (BranchTransferService::validateCreation) via the Create page.
 */
class TransferForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('Transfer')
                            ->schema([
                                Select::make('source_branch_id')
                                    ->label('From branch')
                                    ->relationship('sourceBranch', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->default(function () {
                                        return Branch::withoutGlobalScopes()
                                            ->where('type', 'main')
                                            ->orderBy('id')
                                            ->value('id');
                                    })
                                    ->live(),

                                Select::make('destination_branch_id')
                                    ->label('To branch')
                                    ->relationship('destinationBranch', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->live(),

                                Select::make('type')
                                    ->label('Transfer type')
                                    ->options(TransferType::class)
                                    ->required()
                                    ->default(TransferType::Supply->value)
                                    ->live(),

                                DatePicker::make('transfer_date')
                                    ->label('Transfer date')
                                    ->required()
                                    ->default(now()->toDateString()),

                                DatePicker::make('expected_date')
                                    ->label('Expected date'),

                                TextInput::make('reference')
                                    ->label('Reference / challan no.')
                                    ->maxLength(255),

                                TextInput::make('payment_terms_days')
                                    ->label('Payment terms (days)')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(BranchTransferService::DEFAULT_PAYMENT_TERMS_DAYS)
                                    ->helperText('Net 30 by default. The due date is the transfer date + these days.'),

                                Textarea::make('notes')
                                    ->label('Notes')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        Section::make('Lines')
                            ->schema([
                                Repeater::make('lines')
                                    ->relationship('lines')
                                    ->schema([
                                        Select::make('product_id')
                                            ->label('Product')
                                            ->relationship('product', 'name', fn (Builder $query) => $query
                                                ->where('is_configurable', false)
                                                ->whereNull('parent_id'))
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, Get $get, ?int $state) {
                                                if (! $state) {
                                                    return;
                                                }

                                                $product = Product::query()->find($state);

                                                if (! $product) {
                                                    return;
                                                }

                                                $destinationId = (int) ($get('../../destination_branch_id') ?? 0);
                                                $destination = $destinationId
                                                    ? Branch::withoutGlobalScopes()->find($destinationId)
                                                    : null;

                                                $set('transfer_price_unit', app(BranchTransferPriceService::class)
                                                    ->suggestPrice($product, $destination));

                                                $set('uom_id', $product->uom_id);

                                                $sourceId = (int) ($get('../../source_branch_id') ?? 0);
                                                $source = $sourceId
                                                    ? Branch::withoutGlobalScopes()->find($sourceId)
                                                    : null;

                                                $set('available_qty', $source
                                                    ? app(BranchTransferService::class)->availableQuantity($product, $source->warehouse_id)
                                                    : null);

                                                static::refreshSubtotal($set, $get);
                                            }),

                                        TextInput::make('quantity')
                                            ->label('Qty')
                                            ->numeric()
                                            ->required()
                                            ->minValue(0.0001)
                                            ->default(1)
                                            ->live()
                                            ->afterStateUpdated(fn (Set $set, Get $get) => static::refreshSubtotal($set, $get)),

                                        TextInput::make('available_qty')
                                            ->label('Available at source')
                                            ->numeric()
                                            ->disabled()
                                            ->dehydrated(false),

                                        TextInput::make('transfer_price_unit')
                                            ->label('Transfer price (P)')
                                            ->numeric()
                                            ->required()
                                            ->minValue(0)
                                            ->live()
                                            ->afterStateUpdated(fn (Set $set, Get $get) => static::refreshSubtotal($set, $get))
                                            ->helperText('Suggested from the price rules — override freely.'),

                                        TextInput::make('price_subtotal')
                                            ->label('Line total')
                                            ->numeric()
                                            ->disabled()
                                            ->dehydrated(true),
                                    ])
                                    ->columns(5)
                                    ->minItems(1)
                                    ->required()
                                    ->addActionLabel('Add product line')
                                    ->reorderable(false),
                            ]),
                    ]),
            ]);
    }

    protected static function refreshSubtotal(Set $set, Get $get): void
    {
        $set('price_subtotal', (float) ($get('quantity') ?? 0) * (float) ($get('transfer_price_unit') ?? 0));
    }
}
