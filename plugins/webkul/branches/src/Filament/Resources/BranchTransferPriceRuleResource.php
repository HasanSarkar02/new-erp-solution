<?php

namespace Webkul\Branch\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Pages\CreateBranchTransferPriceRule;
use Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Pages\EditBranchTransferPriceRule;
use Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Pages\ListBranchTransferPriceRules;
use Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Schemas\PriceRuleForm;
use Webkul\Branch\Filament\Resources\BranchTransferPriceRuleResource\Tables\PriceRulesTable;
use Webkul\Branch\Models\BranchTransferPriceRule;

/**
 * Transfer Price Rules list/form (plan §8.5).
 *
 * A rule is either a fixed transfer price or a markup % over cost
 * (fixed wins when both are set). Product rules beat category rules;
 * branch-specific rules beat branch-less rules — see
 * BranchTransferPriceService for the full order.
 */
class BranchTransferPriceRuleResource extends Resource
{
    protected static ?string $model = BranchTransferPriceRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): string
    {
        return 'Branches';
    }

    public static function getNavigationLabel(): string
    {
        return 'Transfer Price Rules';
    }

    public static function form(Schema $schema): Schema
    {
        return PriceRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PriceRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBranchTransferPriceRules::route('/'),
            'create' => CreateBranchTransferPriceRule::route('/create'),
            'edit'   => EditBranchTransferPriceRule::route('/{record}/edit'),
        ];
    }
}
