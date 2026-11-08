<?php

namespace App\Filament\Admin\Resources\PrivateRentals;

use App\Filament\Admin\Resources\PrivateRentals\Pages\ListPrivateRentals;
use App\Filament\Admin\Resources\PrivateRentals\Tables\PrivateRentalsTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\PrivateRental;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Every private rental booking across rooms, Door+ while private rentals
 * are on (PrivateRentalPolicy). List only: bookings are made at the desk,
 * and changed or cancelled from here or the renter's member card.
 */
class PrivateRentalResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = PrivateRental::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Front of House';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return PrivateRentalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrivateRentals::route('/'),
        ];
    }
}
