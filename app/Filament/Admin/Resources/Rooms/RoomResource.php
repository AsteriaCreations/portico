<?php

namespace App\Filament\Admin\Resources\Rooms;

use App\Filament\Admin\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Admin\Resources\Rooms\Pages\EditRoom;
use App\Filament\Admin\Resources\Rooms\Pages\ListRooms;
use App\Filament\Admin\Resources\Rooms\Schemas\RoomForm;
use App\Filament\Admin\Resources\Rooms\Tables\RoomsTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\Room;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Manager+ catalog of rooms for private rentals. Hidden while
 * private_rentals_enabled is off (RoomPolicy::viewAny()).
 */
class RoomResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Desk & Money';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RoomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoomsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRooms::route('/'),
            'create' => CreateRoom::route('/create'),
            'edit' => EditRoom::route('/{record}/edit'),
        ];
    }
}
