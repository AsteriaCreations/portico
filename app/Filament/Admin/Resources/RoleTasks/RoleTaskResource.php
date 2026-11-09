<?php

namespace App\Filament\Admin\Resources\RoleTasks;

use App\Enums\RoleTaskStatus;
use App\Filament\Admin\Resources\RoleTasks\Pages\CreateRoleTask;
use App\Filament\Admin\Resources\RoleTasks\Pages\EditRoleTask;
use App\Filament\Admin\Resources\RoleTasks\Pages\ListRoleTasks;
use App\Filament\Admin\Resources\RoleTasks\Schemas\RoleTaskForm;
use App\Filament\Admin\Resources\RoleTasks\Tables\RoleTasksTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\RoleTask;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Manager+ keeps each shift role's standard task list here, grouped by
 * role. Staff proposals aren't listed: only standard tasks.
 */
class RoleTaskResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = RoleTask::class;

    protected static ?string $slug = 'task-lists';

    protected static ?string $navigationLabel = 'Task lists';

    protected static ?string $modelLabel = 'task';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', RoleTaskStatus::Standard);
    }

    public static function form(Schema $schema): Schema
    {
        return RoleTaskForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoleTasksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoleTasks::route('/'),
            'create' => CreateRoleTask::route('/create'),
            'edit' => EditRoleTask::route('/{record}/edit'),
        ];
    }
}
