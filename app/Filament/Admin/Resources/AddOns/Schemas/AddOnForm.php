<?php

namespace App\Filament\Admin\Resources\AddOns\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AddOnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(60),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('max_per_night')
                    ->label('Max per night')
                    ->numeric()
                    ->minValue(1)
                    ->default(null)
                    ->helperText('Caps how many can be sold across all of a night\'s events, e.g. a single rentable room. Leave blank for unlimited.'),
                Toggle::make('is_overnight')
                    ->label('Overnight stay')
                    ->default(false)
                    ->helperText('Marks this add-on as recording an overnight stay (e.g. Private room rental, Sleepover) — extends sponsor accountability and Active Patrons visibility past midnight until the guest is checked out.'),
                TextInput::make('description')
                    ->maxLength(255)
                    ->default(null),
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
