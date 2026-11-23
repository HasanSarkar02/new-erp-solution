<?php

namespace Webkul\Branch\Filament\Resources\BranchTransferSaleResource\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Transfer view page (plan §8.2): header totals, lines with ordered /
 * shipped / received and P and C per line, history.
 * Stock Operation, invoices and payments sections are added in
 * Phases 3 and 4 — the fields they read (operation_id, out/in
 * invoice ids) are NULL until then.
 */
class TransferInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('Transfer')
                            ->schema([
                                TextEntry::make('name')->label('Number'),
                                TextEntry::make('status')->label('Status')->badge(),
                                TextEntry::make('type')->label('Type')->badge(),
                                TextEntry::make('sourceBranch.name')->label('From branch'),
                                TextEntry::make('destinationBranch.name')->label('To branch'),
                                TextEntry::make('transfer_date')->label('Transfer date')->date(),
                                TextEntry::make('expected_date')->label('Expected date')->date(),
                                TextEntry::make('due_date')->label('Payment due')->date(),
                                TextEntry::make('reference')->label('Reference'),
                                TextEntry::make('creator.name')->label('Created by'),
                            ])
                            ->columns(3),

                        Section::make('Totals')
                            ->schema([
                                TextEntry::make('amount_total')->label('Transfer total')->money('BDT'),
                                TextEntry::make('amount_cost_total')->label('Cost total (FIFO, at ship)')->money('BDT'),
                                TextEntry::make('amount_paid')->label('Paid')->money('BDT'),
                                TextEntry::make('amount_residual')->label('Outstanding')->money('BDT'),
                            ])
                            ->columns(4),

                        Section::make('Lines')
                            ->schema([
                                RepeatableEntry::make('lines')
                                    ->schema([
                                        TextEntry::make('product.name')->label('Product'),
                                        TextEntry::make('quantity')->label('Ordered')->numeric(),
                                        TextEntry::make('quantity_shipped')->label('Shipped')->numeric(),
                                        TextEntry::make('quantity_received')->label('Received')->numeric(),
                                        TextEntry::make('transfer_price_unit')->label('Price (P)')->money('BDT'),
                                        TextEntry::make('cost_price_unit')->label('Cost (C)')->money('BDT')->placeholder('At ship'),
                                        TextEntry::make('price_subtotal')->label('Line total')->money('BDT'),
                                    ])
                                    ->columns(7),
                            ]),

                        Section::make('History')
                            ->schema([
                                RepeatableEntry::make('histories')
                                    ->schema([
                                        TextEntry::make('created_at')->label('When')->dateTime(),
                                        TextEntry::make('user.name')->label('By')->placeholder('System'),
                                        TextEntry::make('from_status')->label('From')->badge()->placeholder('—'),
                                        TextEntry::make('to_status')->label('To')->badge(),
                                        TextEntry::make('note')->label('Note')->placeholder('—'),
                                    ])
                                    ->columns(5),
                            ]),
                    ]),
            ]);
    }
}
