<?php

namespace App\Filament\Admin\Resources\DoorDiscounts\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DoorDiscountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(60)
                    ->helperText(__('What the desk picks, e.g. "Food donation".')),
                TextInput::make('description')
                    ->maxLength(255)
                    ->default(null),
                TextInput::make('amount')
                    ->label(__('Amount off entry'))
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->helperText(__('Taken off the entry still owed after any subscription credit or comp, never below zero. Pool and add-ons are not discounted.')),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('active')
                    ->required()
                    ->default(true),
            ]);
    }
}
