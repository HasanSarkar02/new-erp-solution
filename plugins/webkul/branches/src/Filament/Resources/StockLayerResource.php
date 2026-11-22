<?php

namespace Webkul\Branch\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Webkul\Branch\Filament\Resources\StockLayerResource\Pages\ListStockLayers;
use Webkul\Branch\Filament\Resources\StockLayerResource\Tables\StockLayersTable;
use Webkul\Branch\Models\StockLayer;

/**
 * Stock Layers list (plan §8.4, Phase 5) — read-only.
 *
 * One row per FIFO receipt batch: where the stock sits, how much
 * is left, valued at the transfer price P (original cost C kept
 * for the elimination). Layers are written only by the transfer
 * and write-off services, never by hand.
 */
class StockLayerResource extends Resource
{
    protected static ?string $model = StockLayer::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): string
    {
        return 'Branches';
    }

    public static function getNavigationLabel(): string
    {
        return 'Stock Layers';
    }

    public static function table(Table $table): Table
    {
        return StockLayersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockLayers::route('/'),
        ];
    }
}
