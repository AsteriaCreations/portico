<?php

namespace App\Filament\Admin\Resources\ShiftRoles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
                Toggle::make('open_to_all')
                    ->label(__('Anyone can work it'))
                    ->helperText(__('For jobs like Setup that just need people: everyone with any shift role is offered its shifts, and "Who can work it" is ignored. Its shifts are added one at a time, not counted in the standard shifts.'))
                    ->live()
                    ->default(false),
                // Synced by the Create/Edit page's saveRelationships().
                Select::make('users')
                    ->label(__('Who can work it'))
                    ->helperText(__('Only these staff can give availability for, or be scheduled on, this role\'s shifts.'))
                    ->relationship('users', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true)->orderBy('name'))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->hidden(fn (Get $get): bool => (bool) $get('open_to_all'))
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
