<?php

namespace App\Filament\Admin\Resources\EventTypes\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Models\MembershipSetting;
use App\Models\ShiftTemplate;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The shifts every event of this type needs. ShiftSlotService copies them
 * onto each new event; editing them here never moves a shift already on an
 * event.
 */
class ShiftTemplatesRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'shiftTemplates';

    protected static ?string $title = 'Staff shifts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && MembershipSetting::current()->staff_scheduling_enabled;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('shift_role_id')
                    ->label(__('Shift role'))
                    ->relationship('shiftRole', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true)->orderBy('sort_order'))
                    ->required(),
                TextInput::make('start_offset_minutes')
                    ->label(__('Starts (minutes from the event\'s start)'))
                    ->helperText(__('0 starts with the event. Negative starts before it: -30 is half an hour before doors.'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(-1440)
                    ->maxValue(1440)
                    ->default(0),
                TextInput::make('duration_minutes')
                    ->label(__('Length (minutes)'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(15)
                    ->maxValue(1440)
                    ->default(240),
                TextInput::make('headcount')
                    ->label(__('People needed'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(50)
                    ->default(1),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('shiftRole.name')
            ->defaultSort('start_offset_minutes')
            ->columns([
                TextColumn::make('shiftRole.name')
                    ->label(__('Shift role')),
                TextColumn::make('start_offset_minutes')
                    ->label(__('Starts'))
                    ->formatStateUsing(fn (ShiftTemplate $record): string => static::describeOffset($record->start_offset_minutes)),
                TextColumn::make('duration_minutes')
                    ->label(__('Length'))
                    ->formatStateUsing(fn (int $state): string => static::describeMinutes($state)),
                TextColumn::make('headcount')
                    ->label(__('People needed'))
                    ->numeric(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function describeOffset(int $minutes): string
    {
        return match (true) {
            $minutes === 0 => __('When the event starts'),
            $minutes < 0 => __(':time before the event starts', ['time' => static::describeMinutes(-$minutes)]),
            default => __(':time after the event starts', ['time' => static::describeMinutes($minutes)]),
        };
    }

    public static function describeMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return match (true) {
            $hours === 0 => __(':minutes min', ['minutes' => $rest]),
            $rest === 0 => trans_choice(':count hour|:count hours', $hours),
            default => __(':hours h :minutes min', ['hours' => $hours, 'minutes' => $rest]),
        };
    }
}
