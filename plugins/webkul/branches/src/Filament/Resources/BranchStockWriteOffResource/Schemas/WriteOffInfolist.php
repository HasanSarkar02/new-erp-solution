<?php

namespace Webkul\Branch\Filament\Resources\BranchStockWriteOffResource\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WriteOffInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Write-off')
                ->schema([
                    TextEntry::make('name')->label('Document'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('branch.name')->label('Branch'),
                    TextEntry::make('transfer.name')->label('Transfer')->placeholder('—'),
                    TextEntry::make('amount_total')->label('Loss at stock value')->money('BDT'),
                    TextEntry::make('investigation_note')->label('Investigation note')->columnSpanFull(),
                    TextEntry::make('approver.name')->label('Approved by')->placeholder('—'),
                    TextEntry::make('approved_at')->label('Approved at')->dateTime()->placeholder('—'),
                    TextEntry::make('posted_at')->label('Posted at')->dateTime()->placeholder('—'),
                    TextEntry::make('forgiver.name')->label('Forgiven by')->placeholder('—'),
                    TextEntry::make('forgiven_at')->label('Forgiven at')->dateTime()->placeholder('—'),
                ])
                ->columns(2),

            Section::make('Lines')
                ->schema([
                    RepeatableEntry::make('lines')
                        ->label('')
                        ->schema([
                            TextEntry::make('product.name')->label('Product'),
                            TextEntry::make('quantity')->label('Qty')->numeric(decimalPlaces: 2),
                            TextEntry::make('unit_value')->label('Unit value (P)')->money('BDT'),
                            TextEntry::make('loss_subtotal')->label('Loss')->money('BDT'),
                            TextEntry::make('scrap.name')->label('Scrap record')->placeholder('Not scrapped yet'),
                        ])
                        ->columns(5),
                ]),
        ]);
    }
}
