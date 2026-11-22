<?php

namespace Webkul\Branch\Filament\Resources\StockLayerResource\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StockLayersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('location.name')
                    ->label('Location')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('quantity_remaining')
                    ->label('Qty left')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                TextColumn::make('quantity_initial')
                    ->label('Qty in')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unit_cost')
                    ->label('Unit value')
                    ->money('BDT'),

                TextColumn::make('original_cost_unit')
                    ->label('Original cost')
                    ->money('BDT')
                    ->placeholder('—'),

                TextColumn::make('remaining_value')
                    ->label('Remaining value')
                    ->money('BDT')
                    ->state(fn ($record) => round((float) $record->quantity_remaining * (float) $record->unit_cost, 4)),

                TextColumn::make('source_type')
                    ->label('Source')
                    ->badge(),

                TextColumn::make('incoming_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('incoming_at', 'desc');
    }
}
