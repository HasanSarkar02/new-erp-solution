<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Enums\TransferType;

class TransfersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Number')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('transfer_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                TextColumn::make('sourceBranch.name')
                    ->label('From')
                    ->searchable(),

                TextColumn::make('destinationBranch.name')
                    ->label('To')
                    ->searchable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('lines_count')
                    ->label('Items')
                    ->counts('lines'),

                TextColumn::make('amount_total')
                    ->label('Total')
                    ->money('BDT')
                    ->sortable(),

                TextColumn::make('amount_residual')
                    ->label('Outstanding')
                    ->money('BDT')
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Due')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('creator.name')
                    ->label('Created by')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(TransferStatus::class),

                SelectFilter::make('type')
                    ->label('Type')
                    ->options(TransferType::class),

                SelectFilter::make('source_branch_id')
                    ->label('From branch')
                    ->relationship('sourceBranch', 'name'),

                SelectFilter::make('destination_branch_id')
                    ->label('To branch')
                    ->relationship('destinationBranch', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
