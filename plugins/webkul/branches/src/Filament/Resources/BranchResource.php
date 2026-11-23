<?php

namespace Webkul\Branch\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Webkul\Branch\Filament\Resources\BranchResource\Pages\CreateBranch;
use Webkul\Branch\Filament\Resources\BranchResource\Pages\EditBranch;
use Webkul\Branch\Filament\Resources\BranchResource\Pages\ListBranches;
use Webkul\Branch\Filament\Resources\BranchResource\Pages\ViewBranch;
use Webkul\Branch\Filament\Resources\BranchResource\Schemas\BranchForm;
use Webkul\Branch\Filament\Resources\BranchResource\Schemas\BranchInfolist;
use Webkul\Branch\Filament\Resources\BranchResource\Tables\BranchesTable;
use Webkul\Branch\Models\Branch;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = true;

    public static function getNavigationGroup(): string
    {
        return 'Branches';
    }

    public static function form(Schema $schema): Schema
    {
        return BranchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BranchesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BranchInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'view'   => ViewBranch::route('/{record}'),
            'edit'   => EditBranch::route('/{record}/edit'),
        ];
    }
}
