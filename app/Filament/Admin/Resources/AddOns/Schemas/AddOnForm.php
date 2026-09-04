<?php

namespace App\Filament\Admin\Resources\AddOns\Schemas;

use App\Models\AddOn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AddOnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(60)
                    // The one protected 'entry' row's name is never editable
                    // -- every Regular subscription is keyed off it (see the
                    // add_ons migration's own comment). Mirrors
                    // Category::PROTECTED_NAMES' disabled()/dehydrated(false)
                    // technique.
                    ->disabled(fn (?AddOn $record): bool => $record?->name === AddOn::ENTRY_NAME)
                    ->dehydrated(fn (?AddOn $record): bool => $record?->name !== AddOn::ENTRY_NAME),
                Toggle::make('subscribable')
                    ->helperText('Lets a Plan be created for this add-on, so a member can subscribe to cover it monthly instead of paying per visit.'),
                Toggle::make('priced_per_event')
                    ->label('Priced per event')
                    ->live()
                    ->helperText('Only meaningful for Pool today -- its price varies by event (events.pool_fee) rather than one flat catalog price.')
                    ->visible(fn (?AddOn $record): bool => $record?->name === AddOn::POOL_NAME),
                TextInput::make('price')
                    ->required(fn (Get $get): bool => ! $get('priced_per_event'))
                    ->numeric()
                    ->prefix('$')
                    ->visible(fn (Get $get): bool => ! $get('priced_per_event')),
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
