<?php

namespace App\Filament\Admin\Resources\RoleTasks\Schemas;

use App\Models\ShiftRole;
use App\Models\TaskArea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RoleTaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shift_role_id')
                    ->label(__('Shift role'))
                    ->helperText(__('A task for a role on event shifts is done once each shift; for a role that isn\'t scheduled, such as Cleaning, once each week.'))
                    ->relationship('shiftRole', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true)->orderBy('sort_order')->orderBy('name'))
                    ->getOptionLabelFromRecordUsing(fn (ShiftRole $role): string => $role->scheduled ? $role->name : __(':role (weekly)', ['role' => $role->name]))
                    ->required(),
                Select::make('task_area_id')
                    ->label(__('Area'))
                    ->helperText(__('Optional: where it\'s done. Areas are kept under Task areas.'))
                    ->relationship('area', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true)->orderBy('sort_order')->orderBy('name'))
                    ->getOptionLabelFromRecordUsing(fn (TaskArea $area): string => $area->name)
                    ->placeholder(__('No area')),
                TextInput::make('name')
                    ->label(__('Task'))
                    ->helperText(__('What to do, as staff would say it: "Count the float", "Mop the bathrooms".'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('description')
                    ->label(__('Details'))
                    ->maxLength(255)
                    ->default(null),
                TextInput::make('sort_order')
                    ->label(__('Sort order'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->default(0),
                Toggle::make('active')
                    ->label(__('Active'))
                    ->helperText(__('Turn off to take it off the list. What was done before stays on record.'))
                    ->required()
                    ->default(true),
            ]);
    }
}
