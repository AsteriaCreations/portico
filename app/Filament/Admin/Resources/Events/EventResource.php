<?php

namespace App\Filament\Admin\Resources\Events;

use App\Filament\Admin\Resources\Events\Pages\CreateEvent;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\RelationManagers\AttendanceRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\CompListRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\CompRequestsRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\PoolDayPassesRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\PrepayListRelationManager;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Admin\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AttendanceRelationManager::class,
            PrepayListRelationManager::class,
            CompListRelationManager::class,
            CompRequestsRelationManager::class,
            PoolDayPassesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
