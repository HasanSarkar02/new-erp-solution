<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class PriceRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Price rule')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->relationship('product', 'name', fn (Builder $query) => $query
                                ->where('is_configurable', false)
                                ->whereNull('parent_id'))
                            ->searchable()
                            ->preload()
                            ->helperText('Leave empty for a category rule.'),

                        Select::make('category_id')
                            ->label('Category')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Used only when no product is set.'),

                        Select::make('destination_branch_id')
                            ->label('For branch')
                            ->relationship('destinationBranch', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('Leave empty to apply to all Sub branches.'),

                        TextInput::make('transfer_price')
                            ->label('Fixed transfer price')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Wins over markup % when both are set.'),

                        TextInput::make('markup_percent')
                            ->label('Markup % over cost')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->suffix('%'),

                        DatePicker::make('effective_from')->label('Effective from'),
                        DatePicker::make('effective_to')->label('Effective to'),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
