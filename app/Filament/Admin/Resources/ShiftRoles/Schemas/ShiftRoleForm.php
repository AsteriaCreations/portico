<?php

namespace App\Filament\Admin\Resources\ShiftRoles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ShiftRoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->helperText(__('The job, as staff know it: Door, DM, Bar, Setup…'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(60),
                TextInput::make('description')
                    ->label(__('Description'))
                    ->maxLength(255)
                    ->default(null),
                // Synced by the Create/Edit page's saveRelationships().
                Select::make('users')
                    ->label(__('Who can work it'))
                    ->helperText(__('Only these staff can give availability for, or be scheduled on, this role\'s shifts.'))
                    ->relationship('users', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true)->orderBy('name'))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),
                TextInput::make('sort_order')
                    ->label(__('Sort order'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->default(0),
                Toggle::make('active')
                    ->label(__('Active'))
                    ->helperText(__('Turn off to stop adding this role\'s shifts to new events. Shifts already on events keep it.'))
                    ->required()
                    ->default(true),
            ]);
    }
}
