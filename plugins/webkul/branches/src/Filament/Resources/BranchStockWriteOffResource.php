<?php

namespace Webkul\Branch\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Pages\CreateBranchStockWriteOff;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Pages\ListBranchStockWriteOffs;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Pages\ViewBranchStockWriteOff;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Schemas\WriteOffForm;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Schemas\WriteOffInfolist;
use Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Tables\WriteOffsTable;
use Webkul\Branch\Models\BranchStockWriteOff;

/**
 * Lost / missing stock write-offs (addendum A3, Phase 5).
 *
 * A write-off is raised against a received transfer, approved and
 * posted by Main, and forgiven by Main only. The Sub still owes
 * Main until forgiveness — the payable never falls on posting.
 */
class BranchStockWriteOffResource extends Resource
{
    protected static ?string $model = BranchStockWriteOff::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-minus';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string
    {
        return 'Branches';
    }

    public static function getNavigationLabel(): string
    {
        return 'Stock Write-offs';
    }

    public static function form(Schema $schema): Schema
    {
        return WriteOffForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WriteOffInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WriteOffsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBranchStockWriteOffs::route('/'),
            'create' => CreateBranchStockWriteOff::route('/create'),
            'view'   => ViewBranchStockWriteOff::route('/{record}'),
        ];
    }
}
