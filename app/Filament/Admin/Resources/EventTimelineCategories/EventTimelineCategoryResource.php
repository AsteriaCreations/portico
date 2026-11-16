<?php

namespace App\Filament\Admin\Resources\EventTimelineCategories;

use App\Filament\Admin\Resources\EventTimelineCategories\Pages\CreateEventTimelineCategory;
use App\Filament\Admin\Resources\EventTimelineCategories\Pages\EditEventTimelineCategory;
use App\Filament\Admin\Resources\EventTimelineCategories\Pages\ListEventTimelineCategories;
use App\Filament\Admin\Resources\EventTimelineCategories\Schemas\EventTimelineCategoryForm;
use App\Filament\Admin\Resources\EventTimelineCategories\Tables\EventTimelineCategoriesTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\EventTimelineCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin+ list of who event timeline items are for: Public (attendees) or a
 * staff audience from a given role up. See EventTimelineService.
 */
class EventTimelineCategoryResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = EventTimelineCategory::class;

    protected static ?string $modelLabel = 'timeline category';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Members & Events';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return EventTimelineCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventTimelineCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventTimelineCategories::route('/'),
            'create' => CreateEventTimelineCategory::route('/create'),
            'edit' => EditEventTimelineCategory::route('/{record}/edit'),
        ];
    }
}
