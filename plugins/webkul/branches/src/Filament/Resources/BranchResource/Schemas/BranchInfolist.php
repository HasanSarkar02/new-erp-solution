<?php

namespace Webkul\Branch\Filament\Resources\BranchResource\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BranchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('General')
                            ->schema([
                                TextEntry::make('name')->label('Branch name'),
                                TextEntry::make('code')->label('Branch code'),
                                TextEntry::make('type')->label('Type')->badge(),
                                TextEntry::make('company.name')->label('Company'),
                                TextEntry::make('manager.name')->label('Branch manager'),
                                TextEntry::make('warehouse.name')->label('Warehouse'),
                                IconEntry::make('is_active')->label('Active')->boolean(),
                                IconEntry::make('is_main')->label('Main branch')->boolean(),
                                IconEntry::make('is_demo')->label('Demo data')->boolean(),
                            ])
                            ->columns(2),

                        Section::make('Contact & Address')
                            ->schema([
                                TextEntry::make('phone')->label('Phone'),
                                TextEntry::make('mobile')->label('Mobile'),
                                TextEntry::make('email')->label('Email'),
                                TextEntry::make('street1')->label('Street 1'),
                                TextEntry::make('street2')->label('Street 2'),
                                TextEntry::make('city')->label('City'),
                                TextEntry::make('zip')->label('ZIP / Postal code'),
                                TextEntry::make('state.name')->label('State'),
                                TextEntry::make('country.name')->label('Country'),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpan(['lg' => 2]),
            ])
            ->columns(3);
    }
}
