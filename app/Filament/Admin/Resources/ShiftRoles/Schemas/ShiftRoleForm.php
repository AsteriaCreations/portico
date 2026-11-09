<?php

namespace App\Filament\Admin\Resources\ShiftRoles\Schemas;

use App\Models\ShiftRole;
use App\Services\ShiftSlotService;
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
                Toggle::make('counts_toward_requirement')
                    ->label(__('Counts toward the monthly requirement'))
                    ->helperText(__('Turn off for shifts that shouldn\'t count toward the shifts each person owes a month, such as hosting.'))
                    ->default(true),
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
                    ->helperText(fn (Get $get, ?ShiftRole $record): string => static::activeHelp($get('active'), $record))
                    ->live()
                    ->required()
                    ->default(true),
            ]);
    }

    /**
     * Turning a role off leaves its shifts on upcoming events in place, so
     * say how many before it's saved.
     */
    protected static function activeHelp(mixed $active, ?ShiftRole $record): string
    {
        $default = __('Turn off to stop adding this role\'s shifts to new events. Shifts already on events keep it.');

        if ($active || $record === null) {
            return $default;
        }

        $upcoming = app(ShiftSlotService::class)->upcomingShiftsFor($record);

        if ($upcoming['shifts'] === 0) {
            return __('No upcoming events have :role shifts, so turning it off changes nothing already planned.', ['role' => $record->name]);
        }

        return trans_choice(
            ':role has :count shift on upcoming events (:staffed with people scheduled). Turning it off leaves it there: staff can no longer give availability for it, and anyone scheduled stays on it. Once saved, "Remove unstaffed upcoming shifts" at the top of this page clears the ones nobody is on.|:role has :count shifts on upcoming events (:staffed with people scheduled). Turning it off leaves them there: staff can no longer give availability for them, and anyone scheduled stays on them. Once saved, "Remove unstaffed upcoming shifts" at the top of this page clears the ones nobody is on.',
            $upcoming['shifts'],
            ['role' => $record->name, 'staffed' => $upcoming['staffed']],
        );
    }
}
