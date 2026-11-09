<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\ShiftSlot;
use App\Models\ShiftWeekNote;
use App\Services\ShiftGridService;
use App\Services\ShiftScheduleService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Admin+ builds a schedule week: who is on each shift, picked from the
 * people who can work its role, listed by what they said on My
 * availability. Then publishes it to staff. The rules are in
 * ShiftScheduleService.
 */
class StaffSchedule extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.staff-schedule';

    protected static ?string $slug = 'staff-schedule';

    protected static ?string $title = 'Staff schedule';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 4;

    /** The first day (Y-m-d) of the schedule week on screen. */
    public string $weekStart = '';

    /** 'edit' (pick who works each shift) or 'grid' (read-only, drafts included). */
    public string $display = 'edit';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /** @var array{week: string, slots: Collection<int, ShiftSlot>}|null */
    protected ?array $slotCache = null;

    public static function canAccess(): bool
    {
        return Gate::allows('build-staff-schedule');
    }

    public function mount(): void
    {
        // The schedule is usually built for the week ahead.
        $this->weekStart = $this->service()->weekStart(now())->addWeek()->toDateString();
        $this->loadWeek();
    }

    public function getSubheading(): string
    {
        $start = $this->week();
        $slots = $this->slots();
        $staffed = $slots->filter(fn (ShiftSlot $slot): bool => $slot->assignments->count() >= $slot->headcount)->count();
        $publication = $this->service()->publication($start);

        return implode(' · ', array_filter([
            __('Week of :start to :end', ['start' => $start->isoFormat('ddd ll'), 'end' => $start->addDays(6)->isoFormat('ddd ll')]),
            $slots->isEmpty() ? null : __(':staffed of :total shifts fully staffed', ['staffed' => $staffed, 'total' => $slots->count()]),
            $publication
                ? __('Published :date by :name', ['date' => $publication->published_at->isoFormat('lll'), 'name' => $publication->publishedBy?->name])
                : __('Not published: staff can\'t see it yet'),
        ]));
    }

    public function form(Schema $schema): Schema
    {
        $slots = $this->slots();
        $notes = $this->service()->notesFor($this->week());

        $days = $slots->isEmpty()
            ? [Text::make(__('There are no shifts this week. Shifts are set on the Staffing page.'))]
            : $slots
                ->groupBy(fn (ShiftSlot $slot): string => $slot->starts_at->toDateString())
                ->map(fn (Collection $daySlots, string $date): Section => Section::make(CarbonImmutable::parse($date)->isoFormat('dddd ll'))
                    ->components($daySlots->map(fn (ShiftSlot $slot): Select => $this->slotField($slot))->all()))
                ->values()
                ->all();

        return $schema
            ->statePath('data')
            ->components([
                ...($notes->isEmpty() ? [] : [
                    Section::make(__('Notes from staff'))
                        ->components($notes->map(fn (ShiftWeekNote $note): Text => Text::make(__(':name: :note', ['name' => $note->user?->name, 'note' => $note->note])))->all()),
                ]),
                ...$days,
            ]);
    }

    protected function slotField(ShiftSlot $slot): Select
    {
        $locked = $this->service()->isLocked($slot);

        return Select::make('slot_'.$slot->id)
            ->label(__(':role, :event, :start to :end (needs :count)', [
                'role' => $slot->displayName(),
                'event' => $slot->event->name,
                'start' => $slot->starts_at->isoFormat('LT'),
                'end' => $slot->ends_at->isoFormat('LT'),
                'count' => $slot->headcount,
            ]))
            ->helperText($locked ? __('This shift has started, so it can\'t be changed.') : $slot->notes)
            ->options($this->service()->candidateOptions($slot))
            ->multiple()
            ->maxItems($slot->headcount)
            ->placeholder(__('No one yet'))
            ->disabled($locked);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('Save'))
            ->action(function (): void {
                abort_unless(static::canAccess(), 403);

                $state = $this->form->getState();
                $people = $this->slots()
                    ->reject(fn (ShiftSlot $slot): bool => $this->service()->isLocked($slot))
                    ->mapWithKeys(fn (ShiftSlot $slot): array => [$slot->id => $state['slot_'.$slot->id] ?? []])
                    ->all();

                try {
                    $changed = $this->service()->assign(auth()->user(), $this->week(), $people);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])
                        ->all());
                }

                $this->slotCache = null;
                $this->loadWeek();

                Notification::make()
                    ->title(__('Schedule saved'))
                    ->body($this->service()->isPublished($this->week()) && $changed > 0
                        ? __('This week is published, so everyone added or taken off a shift has been notified.')
                        : null)
                    ->success()
                    ->send();
            });
    }

    /**
     * The week in the paper-schedule layout, draft or published. Unsaved
     * picks aren't in it: it shows what's saved.
     *
     * @return array<string, mixed>|null
     */
    public function grid(): ?array
    {
        return $this->display === 'grid' ? app(ShiftGridService::class)->build($this->week(), publishedOnly: false) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleLayout')
                ->label(fn (): string => $this->display === 'grid' ? __('Edit view') : __('Grid view'))
                ->icon(fn (): Heroicon => $this->display === 'grid' ? Heroicon::OutlinedPencilSquare : Heroicon::OutlinedTableCells)
                ->color('gray')
                ->action(function (): void {
                    $this->display = $this->display === 'grid' ? 'edit' : 'grid';
                    $this->loadWeek();
                }),
            Action::make('print')
                ->label(__('Print'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->visible(fn (): bool => $this->display === 'grid')
                ->alpineClickHandler('window.print()'),
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
            Action::make('publish')
                ->label(__('Publish week'))
                ->icon(Heroicon::OutlinedMegaphone)
                ->requiresConfirmation()
                ->modalDescription(__('Staff will see this week\'s schedule, and everyone on it gets a notification with their shifts. Save any changes first. After publishing, each change notifies the person it affects.'))
                ->hidden(fn (): bool => $this->slots()->isEmpty() || $this->service()->isPublished($this->week()))
                ->action(function (): void {
                    abort_unless(static::canAccess(), 403);

                    $this->service()->publish(auth()->user(), $this->week());

                    Notification::make()->title(__('Week published'))->success()->send();
                }),
        ];
    }

    /**
     * Unsaved changes are dropped when moving to another week.
     */
    public function moveWeek(int $weeks): void
    {
        $this->weekStart = $this->week()->addWeeks($weeks)->toDateString();
        $this->loadWeek();
    }

    protected function loadWeek(): void
    {
        // Rebuild the form for the week now on screen (see MyAvailability).
        $this->cacheSchema('form');

        $this->form->fill($this->slots()
            ->mapWithKeys(fn (ShiftSlot $slot): array => ['slot_'.$slot->id => $slot->assignments->pluck('user_id')->all()])
            ->all());
    }

    /**
     * @return Collection<int, ShiftSlot>
     */
    protected function slots(): Collection
    {
        if ($this->slotCache === null || $this->slotCache['week'] !== $this->weekStart) {
            $this->slotCache = ['week' => $this->weekStart, 'slots' => $this->service()->slotsFor($this->week())];
        }

        return $this->slotCache['slots'];
    }

    protected function week(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->weekStart ?: $this->service()->weekStart(now())->toDateString());
    }

    protected function service(): ShiftScheduleService
    {
        return app(ShiftScheduleService::class);
    }
}
