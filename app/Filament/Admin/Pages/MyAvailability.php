<?php

namespace App\Filament\Admin\Pages;

use App\Enums\ShiftPreference;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\MembershipSetting;
use App\Models\ShiftSlot;
use App\Services\ShiftAvailabilityService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
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
 * on (give-shift-availability). All the rules are in ShiftAvailabilityService.
 */
class MyAvailability extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.my-availability';

    protected static ?string $slug = 'my-availability';

    protected static ?string $title = 'My availability';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Front of House';

    protected static ?int $navigationSort = 5;

    /** The first day (Y-m-d) of the schedule week on screen. */
    public string $weekStart = '';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /** @var array{week: string, slots: Collection<int, ShiftSlot>}|null */
    protected ?array $slotCache = null;

    public static function canAccess(): bool
    {
        return Gate::allows('give-shift-availability');
    }

    public function mount(): void
    {
        // Availability is usually for the week ahead.
        $this->weekStart = $this->currentWeekStart()->addWeek()->toDateString();
        $this->loadWeek();
    }

    public function getSubheading(): string
    {
        $start = $this->week();

        return __('Week of :start to :end', [
            'start' => $start->isoFormat('ddd ll'),
            'end' => $start->addDays(6)->isoFormat('ddd ll'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $slots = $this->slots();

        if ($slots->isEmpty()) {
            $days = [Text::make(__('There are no shifts for your roles this week yet.'))];
        } else {
            $days = $slots
                ->groupBy(fn (ShiftSlot $slot): string => $slot->starts_at->toDateString())
                ->map(fn (Collection $daySlots, string $date): Section => Section::make(CarbonImmutable::parse($date)->isoFormat('dddd ll'))
                    ->components($daySlots->map(fn (ShiftSlot $slot): ToggleButtons => $this->slotField($slot))->all()))
                ->values()
                ->all();
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

    protected function slotField(ShiftSlot $slot): ToggleButtons
    {
        $locked = app(ShiftAvailabilityService::class)->isLocked($slot);

        return ToggleButtons::make('slot_'.$slot->id)
            ->label(__(':role, :event, :start to :end', [
                'role' => $slot->shiftRole->name,
                'event' => $slot->event->name,
                'start' => $slot->starts_at->isoFormat('LT'),
                'end' => $slot->ends_at->isoFormat('LT'),
            ]))
            ->helperText($locked ? __('This shift has started, so it can\'t be changed.') : $slot->notes)
            ->options(ShiftPreference::class)
            ->inline()
            ->disabled($locked);
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label(__('Save'))
            ->action(function (): void {
                abort_unless(static::canAccess(), 403);

                $state = $this->form->getState();
                $answers = $this->slots()
                    ->mapWithKeys(fn (ShiftSlot $slot): array => [$slot->id => $state['slot_'.$slot->id] ?? null])
                    ->all();

                app(ShiftAvailabilityService::class)->save(auth()->user(), $this->week(), $answers, $state['note'] ?? null);

                Notification::make()->title(__('Availability saved'))->success()->send();
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

    protected function loadWeek(): void
    {
        $service = app(ShiftAvailabilityService::class);
        $slots = $this->slots();

        // The form was built for the week this request started on; rebuild
        // it for the one now on screen before filling it.
        $this->cacheSchema('form');
        $answers = $service->answersFor(auth()->user(), $slots);

        $this->form->fill([
            ...$slots->mapWithKeys(fn (ShiftSlot $slot): array => ['slot_'.$slot->id => $answers[$slot->id]->value ?? null])->all(),
            'note' => $service->noteFor(auth()->user(), $this->week()),
        ]);
    }

    /**
     * @return Collection<int, ShiftSlot>
     */
    protected function slots(): Collection
    {
        if ($this->slotCache === null || $this->slotCache['week'] !== $this->weekStart) {
            $this->slotCache = [
                'week' => $this->weekStart,
                'slots' => app(ShiftAvailabilityService::class)->slotsFor(auth()->user(), $this->week()),
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
