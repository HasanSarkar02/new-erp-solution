<?php

namespace Webkul\Branch\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Webkul\Branch\Enums\TransferStatus;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Pages\CreateBranchTransferSale;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Pages\EditBranchTransferSale;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Pages\ListBranchTransferSales;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Pages\ViewBranchTransferSale;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Schemas\TransferForm;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Schemas\TransferInfolist;
use Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Tables\TransfersTable;
use Webkul\Branch\Models\BranchTransferSale;

class BranchTransferSaleResource extends Resource
{
    protected static ?string $model = BranchTransferSale::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = true;

    public static function getNavigationGroup(): string
    {
        return 'Branches';
    }

    public static function getNavigationLabel(): string
    {
        return 'Internal Transfers';
    }

    public static function form(Schema $schema): Schema
    {
        return TransferForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransfersTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TransferInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBranchTransferSales::route('/'),
            'create' => CreateBranchTransferSale::route('/create'),
            'view'   => ViewBranchTransferSale::route('/{record}'),
            'edit'   => EditBranchTransferSale::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_transfer') ?? false;
    }

    public static function canEdit($record): bool
    {
        return $record->status === TransferStatus::Draft
            && (auth()->user()?->can('edit_transfer') ?? false);
    }

    public static function canDelete($record): bool
    {
        return $record->status === TransferStatus::Draft
            && (auth()->user()?->can('delete_transfer') ?? false);
    }
}
