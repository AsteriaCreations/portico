<?php

namespace App\Filament\Admin\Pages;

use App\Enums\ShiftPreference;
use App\Enums\ShiftPresence;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\MembershipSetting;
use App\Models\ShiftSlot;
use App\Models\User;
use App\Services\ShiftAvailabilityService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Where staff say which shifts they can work, one schedule week at a time:
 * Preferred, Available or No for each shift of a role they can work.
 * Reached by anyone with at least one shift role while staff scheduling is
 * on (give-shift-availability), and by Manager+ (enter-staff-availability),
 * who can pick anyone on the roster and answer for them -- for staff who
 * don't use the app yet. All the rules are in ShiftAvailabilityService.
 */
class MyAvailability extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.my-availability';

    protected static ?string $slug = 'my-availability';

    protected static ?string $title = 'My availability';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 2;

    /** The first day (Y-m-d) of the schedule week on screen. */
    public string $weekStart = '';

    /** Whose availability is on screen; null = nobody picked yet. Checked in subject(). */
    public ?int $personId = null;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * @var array<string, mixed>
     */
    public ?array $personData = [];

    /** @var array{key: string, slots: Collection<int, ShiftSlot>}|null */
    protected ?array $slotCache = null;

    /** @var Collection<int, User>|null */
    protected ?Collection $rosterCache = null;

    /** Whether the signed-in user gives their own availability, asked once per request. */
    protected ?bool $givesOwn = null;

    public static function canAccess(): bool
    {
        return Gate::any(['give-shift-availability', 'enter-staff-availability']);
    }

    public function mount(): void
    {
        // Availability is usually for the week ahead.
        $this->weekStart = $this->currentWeekStart()->addWeek()->toDateString();
        $this->personId = Gate::allows('give-shift-availability') ? auth()->id() : null;
        $this->personForm->fill(['person_id' => $this->personId]);
        $this->loadWeek();
    }

    public function getTitle(): string
    {
        $subject = $this->subject();

        return $subject === null || $subject->is(auth()->user())
            ? __('My availability')
            : __('Availability: :name', ['name' => $subject->name]);
    }

    public function getSubheading(): string
    {
        $start = $this->week();

        return __('Week of :start to :end', [
            'start' => $start->isoFormat('ddd ll'),
            'end' => $start->addDays(6)->isoFormat('ddd ll'),
        ]);
    }

    public function canEnterForOthers(): bool
    {
        return Gate::allows('enter-staff-availability');
    }

    /**
     * Manager+ picks whose availability to enter.
     */
    public function personForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('personData')
            ->components([
                Select::make('person_id')
                    ->label(__('Person'))
                    ->helperText(__('Enter availability for someone who doesn\'t use the app. Their answers show who entered them.'))
                    ->options(fn (): array => ($this->rosterCache ??= app(ShiftAvailabilityService::class)->roster())->pluck('name', 'id')->all())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn ($state) => $this->switchPerson(filled($state) ? (int) $state : null)),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        $subject = $this->subject();

        if ($subject === null) {
            return $schema->statePath('data')->components([
                Text::make(__('Pick a person to enter their availability.')),
            ]);
        }

        $slots = $this->slots();
        $enteredBy = app(ShiftAvailabilityService::class)->enteredByOthersFor($subject, $slots);
        $byNight = $slots->groupBy(fn (ShiftSlot $slot): string => $slot->starts_at->toDateString());

        // Every night with an event gets Off/Out, even with no shifts for
        // their roles that night; nights with shifts list them under it.
        $nights = collect($this->nights())->merge($byNight->keys())->unique()->sort()->values();

        $days = $nights
            ->map(fn (string $night): Section => Section::make(CarbonImmutable::parse($night)->isoFormat('dddd ll'))
                ->components([
                    $this->presenceField($night),
                    ...($byNight->get($night) ?? collect())->map(fn (ShiftSlot $slot): ToggleButtons => $this->slotField($slot, $enteredBy[$slot->id] ?? null))->all(),
                ]))
            ->all();

        if ($slots->isEmpty()) {
            array_unshift($days, Text::make($subject->is(auth()->user())
                ? __('There are no shifts for your roles this week yet.')
                : __('There are no shifts for their roles this week yet.')));
        }

        return $schema
            ->statePath('data')
            ->components([
                ...$days,
                Textarea::make('note')
                    ->label(__('Note for this week'))
                    ->helperText(__('Anything the person making the schedule should know, such as "leaving early Saturday".'))
                    ->maxLength(500)
                    ->rows(2),
            ]);
    }

    /**
     * Off (here anyway) or Out (not here) for a night they aren't working.
     */
    protected function presenceField(string $night): ToggleButtons
    {
        $locked = app(ShiftAvailabilityService::class)->isNightLocked($night);

        return ToggleButtons::make('presence_'.$night)
            ->label(__('If you\'re not on a shift this night'))
            ->helperText($locked ? __('This night is over or its week\'s schedule is out, so it can\'t be changed.') : null)
            ->options(ShiftPresence::class)
            ->inline()
            ->disabled($locked);
    }

    /**
     * @return list<string>
     */
    protected function nights(): array
    {
        return $this->subject() === null ? [] : app(ShiftAvailabilityService::class)->nightsFor($this->week());
    }

    protected function slotField(ShiftSlot $slot, ?string $enteredBy): ToggleButtons
    {
        $locked = app(ShiftAvailabilityService::class)->isLocked($slot);

        $help = array_filter([
            $locked ? __('This shift has started or its week\'s schedule is out, so it can\'t be changed.') : $slot->notes,
            $enteredBy !== null ? __('Entered by :name.', ['name' => $enteredBy]) : null,
        ]);

        return ToggleButtons::make('slot_'.$slot->id)
            ->label(__(':role, :event, :start to :end', [
                'role' => $slot->displayName(),
                'event' => $slot->event->name,
                'start' => $slot->starts_at->isoFormat('LT'),
                'end' => $slot->ends_at->isoFormat('LT'),
            ]))
            ->helperText($help === [] ? null : implode(' ', $help))
            ->options(ShiftPreference::class)
            ->inline()
            ->disabled($locked);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('Save'))
            ->disabled(fn (): bool => $this->subject() === null)
            ->action(function (): void {
                abort_unless(static::canAccess(), 403);

                $subject = $this->subject();
                abort_if($subject === null, 403);

                $state = $this->form->getState();
                $answers = $this->slots()
                    ->mapWithKeys(fn (ShiftSlot $slot): array => [$slot->id => $state['slot_'.$slot->id] ?? null])
                    ->all();

                $presence = collect($this->nights())
                    ->mapWithKeys(fn (string $night): array => [$night => $state['presence_'.$night] ?? null])
                    ->all();

                app(ShiftAvailabilityService::class)->save($subject, $this->week(), $answers, $state['note'] ?? null, auth()->user(), $presence);

                $this->slotCache = null;
                $this->loadWeek();

                Notification::make()
                    ->title($subject->is(auth()->user()) ? __('Availability saved') : __('Availability saved for :name', ['name' => $subject->name]))
                    ->success()
                    ->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousWeek')
                ->label(__('Previous week'))
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->disabled(fn (): bool => $this->week()->lte($this->currentWeekStart()))
                ->action(fn () => $this->moveWeek(-1)),
            Action::make('nextWeek')
                ->label(__('Next week'))
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition('after')
                ->color('gray')
                ->action(fn () => $this->moveWeek(1)),
        ];
    }

    /**
     * Unsaved choices are dropped when moving to another week, like leaving
     * the page.
     */
    public function moveWeek(int $weeks): void
    {
        $target = $this->week()->addWeeks($weeks);

        // Never back past the current week: those shifts are over.
        $this->weekStart = $target->lt($this->currentWeekStart())
            ? $this->currentWeekStart()->toDateString()
            : $target->toDateString();
        $this->loadWeek();
    }

    /**
     * Unsaved choices are dropped when switching person, too.
     */
    public function switchPerson(?int $personId): void
    {
        abort_unless($this->canEnterForOthers(), 403);

        $this->personId = $personId;
        $this->loadWeek();
    }

    /**
     * Whose availability is on screen: the signed-in user, or for Manager+
     * the person picked -- only someone on the roster, so a forged personId
     * can't reach anyone else.
     */
    protected function subject(): ?User
    {
        $me = auth()->user();

        $this->givesOwn ??= Gate::allows('give-shift-availability');

        if ($this->personId === null || $this->personId === $me->id || ! $this->canEnterForOthers()) {
            return $this->givesOwn ? $me : null;
        }

        $this->rosterCache ??= app(ShiftAvailabilityService::class)->roster();

        return $this->rosterCache->firstWhere('id', $this->personId);
    }

    protected function loadWeek(): void
    {
        // The form was built for the week and person this request started
        // on; rebuild it for the ones now on screen before filling it.
        $this->cacheSchema('form');

        $subject = $this->subject();

        if ($subject === null) {
            $this->form->fill([]);

            return;
        }

        $service = app(ShiftAvailabilityService::class);
        $slots = $this->slots();
        $answers = $service->answersFor($subject, $slots);
        $nights = $this->nights();
        $presence = $service->presenceFor($subject, $nights);

        $this->form->fill([
            ...$slots->mapWithKeys(fn (ShiftSlot $slot): array => ['slot_'.$slot->id => $answers[$slot->id]->value ?? null])->all(),
            ...collect($nights)->mapWithKeys(fn (string $night): array => ['presence_'.$night => $presence[$night]->value ?? null])->all(),
            'note' => $service->noteFor($subject, $this->week()),
        ]);
    }

    /**
     * @return Collection<int, ShiftSlot>
     */
    protected function slots(): Collection
    {
        $subject = $this->subject();

        if ($subject === null) {
            return new Collection;
        }

        $key = $this->weekStart.'|'.$subject->id;

        if ($this->slotCache === null || $this->slotCache['key'] !== $key) {
            $this->slotCache = [
                'key' => $key,
                'slots' => app(ShiftAvailabilityService::class)->slotsFor($subject, $this->week()),
            ];
        }

        return $this->slotCache['slots'];
    }

    protected function week(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->weekStart ?: $this->currentWeekStart()->toDateString());
    }

    protected function currentWeekStart(): CarbonImmutable
    {
        return CarbonImmutable::instance(MembershipSetting::startOfScheduleWeek(now())->startOfDay());
    }
}
