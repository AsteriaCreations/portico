<?php

namespace App\Filament\Admin\Resources\Rooms\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RoomForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(60),
                TextInput::make('description')
                    ->label(__('Description'))
                    ->maxLength(255)
                    ->default(null),
                TextInput::make('hourly_rate')
                    ->label(__('Hourly rate'))
                    ->helperText(__('What the renter pays for the room, per hour booked.'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix('$'),
                TextInput::make('included_people')
                    ->label(__('People included'))
                    ->helperText(__('How many people the room price covers, the renter included. Each person past this pays the extra-person fee.'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('overage_fee')
                    ->label(__('Extra-person fee'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix('$')
                    ->default(0),
                Toggle::make('overage_per_hour')
                    ->label(__('Extra-person fee is per hour'))
                    ->helperText(__('On: each extra person costs this fee for every hour booked. Off: each extra person costs it once.'))
                    ->required()
                    ->default(true),
                TextInput::make('min_hours')
                    ->label(__('Minimum hours'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(24)
                    ->default(1),
                TextInput::make('max_hours')
                    ->label(__('Maximum hours'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(fn (Get $get): int => max(1, (int) $get('min_hours')))
                    ->maxValue(24)
                    ->default(8),
                TextInput::make('sort_order')
                    ->label(__('Sort order'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->default(0),
                Toggle::make('active')
                    ->label(__('Active'))
                    ->helperText(__('Turn off to stop new bookings for this room. Bookings already made keep it.'))
                    ->required()
                    ->default(true),
            ]);
    }
}
