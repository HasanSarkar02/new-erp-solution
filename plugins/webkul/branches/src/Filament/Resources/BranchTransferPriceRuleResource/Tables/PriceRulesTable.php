<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('category.name')
                    ->label('Category')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('destinationBranch.name')
                    ->label('For branch')
                    ->placeholder('All branches'),

                TextColumn::make('transfer_price')
                    ->label('Fixed price')
                    ->money('BDT')
                    ->placeholder('—'),

                TextColumn::make('markup_percent')
                    ->label('Markup %')
                    ->suffix('%')
                    ->placeholder('—'),

                TextColumn::make('effective_from')
                    ->label('From')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('effective_to')
                    ->label('To')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
