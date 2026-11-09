<?php

namespace App\Filament\Admin\Resources\TaskAreas;

use App\Filament\Admin\Resources\TaskAreas\Pages\CreateTaskArea;
use App\Filament\Admin\Resources\TaskAreas\Pages\EditTaskArea;
use App\Filament\Admin\Resources\TaskAreas\Pages\ListTaskAreas;
use App\Filament\Admin\Resources\TaskAreas\Schemas\TaskAreaForm;
use App\Filament\Admin\Resources\TaskAreas\Tables\TaskAreasTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\TaskArea;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Manager+ list of the areas role tasks are grouped by (Bathrooms, Main
 * floor…), each with notes on how it's handled.
 */
class TaskAreaResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = TaskArea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TaskAreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaskAreasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaskAreas::route('/'),
            'create' => CreateTaskArea::route('/create'),
            'edit' => EditTaskArea::route('/{record}/edit'),
        ];
    }
}
