<?php

namespace App\Filament\Admin\Resources\EventTypes;

use App\Filament\Admin\Resources\EventTypes\Pages\CreateEventType;
use App\Filament\Admin\Resources\EventTypes\Pages\EditEventType;
use App\Filament\Admin\Resources\EventTypes\Pages\ListEventTypes;
use App\Filament\Admin\Resources\EventTypes\RelationManagers\InstructorPayRatesRelationManager;
use App\Filament\Admin\Resources\EventTypes\Schemas\EventTypeForm;
use App\Filament\Admin\Resources\EventTypes\Tables\EventTypesTable;
use App\Models\EventType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EventTypeResource extends Resource
{
    protected static ?string $model = EventType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Members & Events';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return EventTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InstructorPayRatesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventTypes::route('/'),
            'create' => CreateEventType::route('/create'),
            'edit' => EditEventType::route('/{record}/edit'),
        ];
    }
}
