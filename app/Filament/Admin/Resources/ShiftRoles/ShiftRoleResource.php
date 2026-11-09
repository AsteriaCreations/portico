<?php

namespace App\Filament\Admin\Resources\ShiftRoles;

use App\Filament\Admin\Resources\ShiftRoles\Pages\CreateShiftRole;
use App\Filament\Admin\Resources\ShiftRoles\Pages\EditShiftRole;
use App\Filament\Admin\Resources\ShiftRoles\Pages\ListShiftRoles;
use App\Filament\Admin\Resources\ShiftRoles\Schemas\ShiftRoleForm;
use App\Filament\Admin\Resources\ShiftRoles\Tables\ShiftRolesTable;
use App\Filament\Concerns\TranslatesResourceLabels;
use App\Models\ShiftRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin+ list of the jobs staff are scheduled for, and who can work each.
 * Hidden while staff_scheduling_enabled is off (ShiftRolePolicy::viewAny()).
 */
class ShiftRoleResource extends Resource
{
    use TranslatesResourceLabels;

    protected static ?string $model = ShiftRole::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return ShiftRoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ShiftRolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShiftRoles::route('/'),
            'create' => CreateShiftRole::route('/create'),
            'edit' => EditShiftRole::route('/{record}/edit'),
        ];
    }
}
