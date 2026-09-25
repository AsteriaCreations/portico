<?php

namespace App\Filament\Admin\Resources\Events;

use App\Filament\Admin\Resources\Events\Pages\CreateEvent;
use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use App\Filament\Admin\Resources\Events\Pages\ListEvents;
use App\Filament\Admin\Resources\Events\RelationManagers\AddOnDayPassesRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\AttendanceRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\CompListRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\CompRequestsRelationManager;
use App\Filament\Admin\Resources\Events\RelationManagers\PrepayListRelationManager;
use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Filament\Admin\Resources\Events\Tables\EventsTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class EventResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * The name is optional, so titles, breadcrumbs and global search use
     * Event::label() (date plus name, or type) rather than a bare "Event".
     */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof Event ? $record->label() : parent::getRecordTitle($record);
    }

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
            AddOnDayPassesRelationManager::class,
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
