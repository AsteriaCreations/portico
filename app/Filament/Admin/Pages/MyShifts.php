<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\ShiftAssignment;
use App\Models\ShiftSlot;
use App\Services\ShiftGridService;
use App\Services\ShiftScheduleService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The published staff schedule, for the people on it: one schedule week at
 * a time, every shift with who's on it, your own marked "You". A week that
 * isn't published yet shows nothing, so a draft never leaks. Reached from
 * the publish and change notifications (?week=) as well as the nav.
 */
class MyShifts extends Page
{
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.my-shifts';

    protected static ?string $slug = 'my-shifts';

    protected static ?string $title = 'My shifts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 1;

    /** The first day (Y-m-d) of the schedule week on screen. */
    #[Url(as: 'week')]
    public string $weekStart = '';

    /** 'list' (day by day, yours first) or 'grid' (the paper-schedule layout). */
    #[Url(as: 'view')]
    public string $display = 'list';

    /** @var array{week: string, slots: Collection<int, ShiftSlot>}|null */
    protected ?array $slotCache = null;

    public static function canAccess(): bool
    {
        return Gate::allows('view-staff-schedule');
    }

    public function mount(): void
    {
        // A link's ?week= may be any day; anything unreadable opens this week.
        try {
            $week = $this->weekStart !== '' ? CarbonImmutable::parse($this->weekStart) : now();
        } catch (\Throwable) {
            $week = now();
        }

        $this->weekStart = $this->service()->weekStart($week)->toDateString();
    }

    public function getSubheading(): string
    {
        $start = $this->week();

        return __('Week of :start to :end', [
            'start' => $start->isoFormat('ddd ll'),
            'end' => $start->addDays(6)->isoFormat('ddd ll'),
        ]);
    }

    public function schedule(Schema $schema): Schema
    {
        $slots = $this->slots();

        if (! $this->service()->isPublished($this->week())) {
            return $schema->components([
                EmptyState::make(__('This week\'s schedule isn\'t out yet'))
                    ->description(__('You\'ll get a notification when it\'s published.'))
                    ->icon(Heroicon::OutlinedCalendar),
            ]);
        }

        if ($slots->isEmpty()) {
            return $schema->components([
                EmptyState::make(__('No shifts this week'))->icon(Heroicon::OutlinedCalendar),
            ]);
        }

        $mine = $slots->filter(fn (ShiftSlot $slot): bool => $this->isMine($slot));

        return $schema->components([
            Section::make(__('Your shifts this week'))
                ->components([
                    $mine->isEmpty()
                        ? Text::make(__('You\'re not on any shifts this week.'))
                        : UnorderedList::make($mine->map(fn (ShiftSlot $slot): Text => Text::make($this->service()->describe($slot)))->values()->all()),
                ]),
            ...$slots
                ->groupBy(fn (ShiftSlot $slot): string => $slot->starts_at->toDateString())
                ->map(fn (Collection $daySlots, string $date): Section => Section::make(CarbonImmutable::parse($date)->isoFormat('dddd ll'))
                    ->components($daySlots->map(fn (ShiftSlot $slot): Grid => $this->shiftRow($slot))->all()))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Always four columns, the last holding whatever badges apply, so every
     * row of the day lines up whether or not it has a badge.
     */
    protected function shiftRow(ShiftSlot $slot): Grid
    {
        $people = $slot->assignments->map(fn (ShiftAssignment $assignment): ?string => $assignment->user?->name)->filter()->sort()->values();
        $open = $slot->headcount - $people->count();

        $badges = array_values(array_filter([
            $open > 0 ? Text::make(trans_choice(':count spot open|:count spots open', $open))->badge()->color('warning')->icon(Heroicon::OutlinedExclamationTriangle) : null,
            $this->isMine($slot) ? Text::make(__('You'))->badge()->color('success')->icon(Heroicon::OutlinedUser) : null,
        ]));

        return Grid::make(['default' => 1, 'md' => 4])->schema([
            Text::make(__(':role, :event', ['role' => $slot->displayName(), 'event' => $slot->event->name]))
                ->weight(FontWeight::SemiBold),
            Text::make(__(':start to :end', ['start' => $slot->starts_at->isoFormat('LT'), 'end' => $slot->ends_at->isoFormat('LT')])),
            Text::make($people->isEmpty() ? __('No one yet') : $people->implode(', ')),
            Flex::make($badges === [] ? [Text::make('')] : $badges),
        ]);
    }

    /**
     * The grid, for the view: published weeks only, like the list.
     *
     * @return array<string, mixed>|null
     */
    public function grid(): ?array
    {
        return $this->display === 'grid' && $this->service()->isPublished($this->week())
            ? app(ShiftGridService::class)->build($this->week(), publishedOnly: true)
            : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleLayout')
                ->label(fn (): string => $this->display === 'grid' ? __('List view') : __('Grid view'))
                ->icon(fn (): Heroicon => $this->display === 'grid' ? Heroicon::OutlinedListBullet : Heroicon::OutlinedTableCells)
                ->color('gray')
                ->action(fn () => $this->display = $this->display === 'grid' ? 'list' : 'grid'),
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
        ];
    }

    public function moveWeek(int $weeks): void
    {
        $this->weekStart = $this->week()->addWeeks($weeks)->toDateString();

        // Rebuild for the week now on screen (see MyAvailability).
        $this->cacheSchema('schedule');
    }

    protected function isMine(ShiftSlot $slot): bool
    {
        return $slot->assignments->contains('user_id', auth()->id());
    }

    /**
     * @return Collection<int, ShiftSlot>
     */
    protected function slots(): Collection
    {
        if ($this->slotCache === null || $this->slotCache['week'] !== $this->weekStart) {
            $this->slotCache = ['week' => $this->weekStart, 'slots' => $this->service()->publishedSlotsFor($this->week())];
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
