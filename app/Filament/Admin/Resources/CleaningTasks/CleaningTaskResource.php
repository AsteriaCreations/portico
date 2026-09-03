<?php

namespace App\Filament\Admin\Resources\CleaningTasks;

use App\Filament\Admin\Resources\CleaningTasks\Pages\CreateCleaningTask;
use App\Filament\Admin\Resources\CleaningTasks\Pages\EditCleaningTask;
use App\Filament\Admin\Resources\CleaningTasks\Pages\ListCleaningTasks;
use App\Filament\Admin\Resources\CleaningTasks\RelationManagers\CompletionsRelationManager;
use App\Filament\Admin\Resources\CleaningTasks\Schemas\CleaningTaskForm;
use App\Filament\Admin\Resources\CleaningTasks\Tables\CleaningTasksTable;
use App\Models\CleaningTask;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class CleaningTaskResource extends Resource
{
    protected static ?string $model = CleaningTask::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CleaningTaskForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningTasksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            CompletionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningTasks::route('/'),
            'create' => CreateCleaningTask::route('/create'),
            'edit' => EditCleaningTask::route('/{record}/edit'),
        ];
    }
}
