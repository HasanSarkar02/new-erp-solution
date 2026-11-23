<?php

namespace Webkul\Branch\Filament\Resources\BranchResource\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;
use Webkul\Branch\Enums\BranchType;
use Webkul\Branch\Models\Branch;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('General')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Branch name')
                                    ->required()
                                    ->maxLength(255)
                                    ->autofocus()
                                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('company_id', $get('company_id'))),

                                TextInput::make('code')
                                    ->label('Branch code')
                                    ->required()
                                    ->maxLength(20)
                                    ->helperText('Short code, e.g. MAIN, DHK-01, CNC-01. Used in transfer numbers.')
                                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('company_id', $get('company_id'))),

                                Select::make('type')
                                    ->label('Branch type')
                                    ->options(BranchType::class)
                                    ->required()
                                    ->live()
                                    ->default(BranchType::Sub->value),

                                Select::make('company_id')
                                    ->label('Company')
                                    ->relationship('company', 'name')
                                    ->required()
                                    ->disabled(fn () => (bool) current_company_id())
                                    ->default(fn () => current_company_id()),

                                Select::make('manager_user_id')
                                    ->label('Branch manager')
                                    ->relationship('manager', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable(),

                                Select::make('warehouse_id')
                                    ->label('Warehouse')
                                    ->relationship('warehouse', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->nullable()
                                    ->visible(fn (Get $get): bool => $get('type') !== BranchType::Service->value)
                                    ->helperText('Leave empty to create a new warehouse automatically. Service branches have no warehouse.'),
                            ]),

                        Section::make('Contact & Address')
                            ->schema([
                                Group::make()
                                    ->schema([
                                        TextInput::make('phone')->label('Phone')->maxLength(255),
                                        TextInput::make('mobile')->label('Mobile')->maxLength(255),
                                        TextInput::make('email')->label('Email')->email()->maxLength(255),
                                    ])
                                    ->columns(3),

                                Group::make()
                                    ->schema([
                                        TextInput::make('street1')->label('Street 1')->maxLength(255),
                                        TextInput::make('street2')->label('Street 2')->maxLength(255),
                                        TextInput::make('city')->label('City')->maxLength(255),
                                        TextInput::make('zip')->label('ZIP / Postal code')->maxLength(255),
                                        Select::make('state_id')->label('State')->relationship('state', 'name')->searchable()->preload()->nullable(),
                                        Select::make('country_id')->label('Country')->relationship('country', 'name')->searchable()->preload()->nullable(),
                                    ])
                                    ->columns(2),
                            ]),
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make()
                    ->schema([
                        Section::make('Status')
                            ->schema([
                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->helperText('Inactive branches cannot receive transfers.'),

                                Toggle::make('is_demo')
                                    ->label('Demo data')
                                    ->helperText('Demo branches are seeded for trying the system and can be deleted.')
                                    ->disabled(fn (?Branch $record): bool => $record === null),
                            ]),
                    ])
                    ->columnSpan(['lg' => 1]),
            ])
            ->columns(3);
    }
}
