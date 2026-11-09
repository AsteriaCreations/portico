<?php

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Enums\ShiftKind;
use App\Filament\Concerns\ReadOnlyWhenEventArchived;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Services\ShiftSlotService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The event's staffing (Manager+): its standard shifts (Open and Close, or
 * one Whole night shift), set together with a number of people per role,
 * plus Setup and extra shifts added one at a time. The rules are in
 * ShiftSlotService.
 */
class ShiftSlotsRelationManager extends RelationManager
{
    use ReadOnlyWhenEventArchived;
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'shiftSlots';

    protected static ?string $title = 'Staffing';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && MembershipSetting::current()->staff_scheduling_enabled;
    }

    /**
     * Adding or editing a Setup or extra shift. Standard shifts are changed
     * through "Set standard shifts" instead.
     */
    public function form(Schema $schema): Schema
    {
        $event = $this->getOwnerRecord();

        return $schema
            ->components([
                Select::make('kind')
                    ->label(__('Kind'))
                    ->options([ShiftKind::Setup->value => ShiftKind::Setup->getLabel(), ShiftKind::Extra->value => ShiftKind::Extra->getLabel()])
                    ->default(ShiftKind::Extra->value)
                    ->required()
                    ->live(),
                TextInput::make('label')
                    ->label(__('Label'))
                    ->helperText(__('Optional, shown with the role: "Late cover", "Bar setup".'))
                    ->maxLength(40),
                Select::make('shift_role_id')
                    ->label(__('Shift role'))
                    ->relationship('shiftRole', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('active', true)->orderBy('sort_order'))
                    ->required(),
                TextInput::make('headcount')
                    ->label(__('People needed'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(50)
                    ->default(1),
                DateTimePicker::make('starts_at')
                    ->label(__('Starts'))
                    ->seconds(false)
                    ->required()
                    ->default(fn (Get $get) => $event instanceof Event ? ($get('kind') === ShiftKind::Setup->value ? $event->starts_at?->copy()->subHours(3) : $event->starts_at) : null),
                DateTimePicker::make('ends_at')
                    ->label(__('Ends'))
                    ->seconds(false)
                    ->required()
                    ->after('starts_at')
                    ->default(fn (Get $get) => $event instanceof Event ? ($get('kind') === ShiftKind::Setup->value ? $event->starts_at : $event->ends_at) : null),
                TextInput::make('notes')
                    ->label(__('Notes'))
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (ShiftSlot $record): string => $record->displayName())
            ->modifyQueryUsing(fn (Builder $query) => $query->with('shiftRole')->withCount('assignments'))
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('shift')
                    ->label(__('Shift'))
                    ->state(fn (ShiftSlot $record): string => $record->displayName())
                    ->description(fn (ShiftSlot $record): ?string => $record->notes),
                TextColumn::make('kind')
                    ->label(__('Kind'))
                    ->badge(),
                TextColumn::make('starts_at')
                    ->label(__('Starts'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('Ends'))
                    ->dateTime(),
                TextColumn::make('headcount')
                    ->label(__('People needed'))
                    ->numeric(),
                TextColumn::make('assignments_count')
                    ->label(__('Scheduled'))
                    ->numeric(),
            ])
            ->headerActions([
                $this->standardShiftsAction(),
                CreateAction::make()
                    ->label(__('Add setup or extra shift')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (ShiftSlot $record): bool => ! $record->kind->isStandard()),
                DeleteAction::make()
                    ->visible(fn (ShiftSlot $record): bool => ! $record->kind->isStandard())
                    ->modalDescription(fn (ShiftSlot $record): ?string => $record->assignments_count > 0
                        ? trans_choice(':count person is scheduled on this shift and will be taken off it.|:count people are scheduled on this shift and will be taken off it.', $record->assignments_count)
                        : null),
            ]);
    }

    /**
     * Open and Close (or Whole night), and how many people each role needs
     * on each, in one form.
     */
    protected function standardShiftsAction(): Action
    {
        $roles = app(ShiftSlotService::class)->standardRoles();
        $handover = MembershipSetting::current()->staff_handover_minutes;

        $countFields = fn (ShiftKind $kind): array => $roles
            ->map(fn (ShiftRole $role): TextInput => TextInput::make("counts.{$kind->value}.{$role->id}")
                ->label($role->name)
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(50)
                ->default(0))
            ->all();

        return Action::make('setStandardShifts')
            ->label(__('Set standard shifts'))
            ->icon(Heroicon::OutlinedUserGroup)
            ->visible(fn (): bool => ! $this->isReadOnly() && Gate::allows('create', ShiftSlot::class))
            ->fillForm(function (): array {
                $service = app(ShiftSlotService::class);
                $event = $this->getOwnerRecord();

                return [
                    'shape' => $service->shapeFor($event),
                    'open_ends_at' => $service->openEndsAtFor($event)?->format('Y-m-d H:i:s'),
                    'counts' => $service->countsFor($event),
                ];
            })
            ->schema([
                Radio::make('shape')
                    ->label(__('Shifts'))
                    ->options([
                        ShiftSlotService::SHAPE_OPEN_CLOSE => __('Open and Close'),
                        ShiftSlotService::SHAPE_SINGLE => __('One shift for the whole night'),
                    ])
                    ->required()
                    ->live(),
                DateTimePicker::make('open_ends_at')
                    ->label(__('Open ends'))
                    ->helperText(trans_choice('Close starts :count minute before this, for the handover, and runs to the end of the event.|Close starts :count minutes before this, for the handover, and runs to the end of the event.', $handover))
                    ->seconds(false)
                    ->required(fn (Get $get): bool => $get('shape') === ShiftSlotService::SHAPE_OPEN_CLOSE)
                    ->visible(fn (Get $get): bool => $get('shape') === ShiftSlotService::SHAPE_OPEN_CLOSE),
                Section::make(__('People on Open'))
                    ->columns(4)
                    ->visible(fn (Get $get): bool => $get('shape') === ShiftSlotService::SHAPE_OPEN_CLOSE)
                    ->components($countFields(ShiftKind::Open)),
                Section::make(__('People on Close'))
                    ->columns(4)
                    ->visible(fn (Get $get): bool => $get('shape') === ShiftSlotService::SHAPE_OPEN_CLOSE)
                    ->components($countFields(ShiftKind::Close)),
                Section::make(__('People on the shift'))
                    ->columns(4)
                    ->visible(fn (Get $get): bool => $get('shape') === ShiftSlotService::SHAPE_SINGLE)
                    ->components($countFields(ShiftKind::Single)),
            ])
            ->action(function (array $data, Action $action): void {
                abort_if($this->isReadOnly(), 403);
                abort_unless(Gate::allows('create', ShiftSlot::class), 403);

                try {
                    $shifts = app(ShiftSlotService::class)->setStandardShifts(
                        $this->getOwnerRecord(),
                        $data['shape'],
                        filled($data['open_ends_at'] ?? null) ? Carbon::parse($data['open_ends_at']) : null,
                        $data['counts'] ?? [],
                    );
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Staffing not saved'))
                        ->body(collect($exception->errors())->flatten()->first())
                        ->danger()
                        ->send();

                    $action->halt();
                }

                Notification::make()
                    ->title(trans_choice('Staffing saved: :count shift|Staffing saved: :count shifts', $shifts))
                    ->success()
                    ->send();
            });
    }
}
