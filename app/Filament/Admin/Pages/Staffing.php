<?php

namespace App\Filament\Admin\Pages;

use App\Enums\ShiftKind;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftSlot;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Manager+ staffs each event here, one schedule week at a time: the week's
 * events with a summary of their shifts, and the picked event's shifts
 * below (ShiftSlotsRelationManager, embedded) to set its standard shifts,
 * add setup or extra shifts, and record who worked. Kept with the rest of
 * staff scheduling rather than as a tab on each event.
 */
class Staffing extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.staffing';

    protected static ?string $slug = 'staffing';

    protected static ?string $title = 'Staffing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 3;

    /** The first day (Y-m-d) of the schedule week on screen. */
    #[Url(as: 'week')]
    public string $weekStart = '';

    /** The event whose shifts are open below; checked in selectedEvent(). */
    #[Url(as: 'event')]
    public ?int $eventId = null;

    public static function canAccess(): bool
    {
        // viewAny carries the staff scheduling flag; create, Manager+.
        return Gate::allows('viewAny', ShiftSlot::class) && Gate::allows('create', ShiftSlot::class);
    }

    public function mount(): void
    {
        $event = $this->eventId ? Event::find($this->eventId) : null;

        // A link to an event opens its week; otherwise the week ahead,
        // when staffing is usually set.
        try {
            $week = $event?->starts_at ?? $event?->event_date ?? ($this->weekStart !== '' ? CarbonImmutable::parse($this->weekStart) : now()->addWeek());
        } catch (\Throwable) {
            $week = now()->addWeek();
        }

        $this->weekStart = $this->scheduleWeek($week)->toDateString();
    }

    public function getSubheading(): string
    {
        $start = $this->week();

        return __('Week of :start to :end', [
            'start' => $start->isoFormat('ddd ll'),
            'end' => $start->addDays(6)->isoFormat('ddd ll'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->weekEvents())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shiftSlots' => fn ($slots) => $slots->with('shiftRole')->withCount('assignments')]))
            ->defaultSort('starts_at')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('Event'))
                    ->weight(fn (Event $record): ?string => $record->id === $this->eventId ? 'bold' : null)
                    ->description(fn (Event $record): ?string => $record->id === $this->eventId ? __('Open below') : null),
                TextColumn::make('starts_at')
                    ->label(__('Starts'))
                    ->dateTime('D M j, g:i A')
                    ->placeholder(fn (Event $record): string => $record->event_date?->isoFormat('ddd ll') ?? ''),
                TextColumn::make('shifts')
                    ->label(__('Shifts'))
                    ->state(fn (Event $record): string => $this->summary($record))
                    ->wrap(),
                TextColumn::make('filled')
                    ->label(__('Scheduled'))
                    ->state(fn (Event $record): string => __(':filled of :needed', [
                        'filled' => $record->shiftSlots->sum('assignments_count'),
                        'needed' => $record->shiftSlots->sum('headcount'),
                    ])),
            ])
            ->recordAction('staff')
            ->recordActions([
                Action::make('staff')
                    ->label(__('Staff it'))
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->action(fn (Event $record) => $this->eventId = $record->id),
            ])
            ->emptyStateHeading(__('No events this week'))
            ->emptyStateDescription(__('Events are added on Events. Use Next week to staff further ahead.'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousWeek')
                ->label(__('Previous week'))
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->action(fn () => $this->moveWeek(-1)),
            Action::make('nextWeek')
                ->label(__('Next week'))
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition('after')
                ->color('gray')
                ->action(fn () => $this->moveWeek(1)),
        ];
    }

    public function moveWeek(int $weeks): void
    {
        $this->weekStart = $this->week()->addWeeks($weeks)->toDateString();
        $this->eventId = null;
        $this->resetTable();
    }

    /**
     * The event picked below, only if it's one of this week's (so a forged
     * id can't open some other event).
     */
    public function selectedEvent(): ?Event
    {
        return $this->eventId === null ? null : $this->weekEvents()->whereKey($this->eventId)->first();
    }

    /**
     * "Open: Desk 2, DM 1 · Close: Desk 1 · Setup 4": each kind with its
     * roles' head counts.
     */
    protected function summary(Event $event): string
    {
        if ($event->shiftSlots->isEmpty()) {
            return __('No shifts yet');
        }

        return $event->shiftSlots
            ->sortBy(fn (ShiftSlot $slot): string => array_search($slot->kind, ShiftKind::cases(), true).$slot->starts_at->format('YmdHi'))
            ->groupBy(fn (ShiftSlot $slot): string => $slot->kind->isStandard() ? $slot->kind->getLabel() : ($slot->label ?: $slot->kind->getLabel()))
            ->map(fn ($slots, string $group): string => __(':group: :roles', [
                'group' => $group,
                'roles' => $slots->map(fn (ShiftSlot $slot): string => $slot->shiftRole?->name.' '.$slot->headcount)->implode(', '),
            ]))
            ->implode(' · ');
    }

    /**
     * Unarchived events starting in the week on screen (by start time, or
     * date when there's none).
     */
    protected function weekEvents(): Builder
    {
        $start = $this->week();
        $end = $start->addWeek();

        return Event::query()
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $timed) => $timed->where('starts_at', '>=', $start)->where('starts_at', '<', $end))
                ->orWhere(fn (Builder $dated) => $dated->whereNull('starts_at')->whereDate('event_date', '>=', $start->toDateString())->whereDate('event_date', '<', $end->toDateString())));
    }

    protected function week(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->weekStart ?: $this->scheduleWeek(now()->addWeek())->toDateString());
    }

    protected function scheduleWeek(\DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance(MembershipSetting::startOfScheduleWeek(CarbonImmutable::instance($date))->startOfDay());
    }
}
