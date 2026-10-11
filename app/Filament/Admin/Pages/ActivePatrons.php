<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Attendance;
use App\Models\AttendanceBehaviorNote;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Services\CapacityService;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Who's actually still in the building right now, across every currently
 * active event at once — distinct from checked-in-roster and CheckIn's own
 * back-check-in table, both of which stay scoped to a single selected event.
 * Lets Volunteer+ depart a specific, known person (Attendance::departed_at)
 * rather than only the anonymous headcount RecordDeparturesWidget offers.
 */
class ActivePatrons extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.active-patrons';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Active Patrons';

    protected static string|UnitEnum|null $navigationGroup = 'Front of House';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return Gate::allows('record-departures');
    }

    /**
     * Per-request memos for the two lists the view reads more than once
     * (the table query and the header both want the events). Protected, so
     * Livewire never persists them: each 15s poll reads fresh.
     */
    protected ?Collection $activeEvents = null;

    protected ?Collection $signedInStaff = null;

    public function getActiveEvents(): Collection
    {
        return $this->activeEvents ??= Event::currentQuery()->orderBy('event_date')->get();
    }

    public function getOccupancy(): int
    {
        return app(CapacityService::class)->occupancy(today());
    }

    public function getCapacity(): ?int
    {
        return app(CapacityService::class)->capacity();
    }

    /**
     * Volunteer+ staff signed into the system right now -- they don't often
     * check in at the front the way a patron does, but being signed in
     * means they're in the building. See User::signedInStaffQuery(). Not
     * folded into getOccupancy()/CapacityService -- that's patron building
     * capacity, a separate concern from noting which staff are on site.
     */
    public function getSignedInStaff(): Collection
    {
        return $this->signedInStaff ??= User::signedInStaffQuery()->orderBy('name')->get();
    }

    /**
     * "Vera Volunteer (Volunteer), Dana DM (DM)", or names only when the
     * club has turned roles off (MembershipSetting::active_patrons_show_staff_roles).
     */
    public function signedInStaffSummary(): string
    {
        $showRoles = MembershipSetting::current()->active_patrons_show_staff_roles;

        return $this->getSignedInStaff()
            ->map(fn (User $user): string => $showRoles ? "{$user->name} ({$user->role->displayLabel()})" : $user->name)
            ->implode(', ');
    }

    public function table(Table $table): Table
    {
        $eventIds = $this->getActiveEvents()->pluck('id');

        return $table
            ->query(Attendance::query()
                ->whereNotNull('checked_in_at')
                ->whereNull('departed_at')
                // An overnight stay (Private room rental/Sleepover-style
                // add-on) stays visible even once its own event drops out of
                // currentQuery() -- departed_at, not the event window, is
                // what removes them here. See MemberObserver's matching
                // accountability logic and docs/BLUEPRINT.md
                // "Still open" (overnight guest handling).
                ->where(fn ($query) => $query
                    ->whereIn('event_id', $eventIds)
                    ->orWhereHas('addOns', fn ($addOns) => $addOns->where('is_overnight', true)))
                ->with(['addOns', 'behaviorNotes.createdBy', 'event', 'member.category', 'member.skills'])
                ->withFirstArrival())
            // Filament layout components (Split/Stack), not a flat column
            // list -- each patron renders as one card, so the page never
            // needs the left/right horizontal scroll a 10-wide table forced.
            // Kept short for a phone: flags show only when they apply, the
            // event and arrival time share a line, and an empty note leaves
            // no placeholder (the row's Visit note / Behavior note buttons
            // add one).
            ->columns([
                Stack::make([
                    Split::make([
                        TextColumn::make('member.username')
                            ->label('Member')
                            ->weight(FontWeight::Bold)
                            ->searchable()
                            ->action($this->departAction()),
                        TextColumn::make('flags')
                            ->label('Flags')
                            ->badge()
                            ->getStateUsing(fn (Attendance $record): array => array_values(array_filter([
                                $record->member?->on_watchlist ? __('Watchlist') : null,
                                $record->member?->isGuest() ? __('Guest') : null,
                                $record->isNewMemberVisit() ? __('New member') : null,
                                $record->isOvernightStay() ? __('Overnight') : null,
                            ])))
                            // The same icons as the desk's checked-in roster.
                            ->icon(fn (string $state): Heroicon => match ($state) {
                                __('Watchlist') => Heroicon::Eye,
                                __('Guest') => Heroicon::UserPlus,
                                __('New member') => Heroicon::Sparkles,
                                default => Heroicon::Moon,
                            })
                            ->color(fn (string $state): string => match ($state) {
                                __('Watchlist') => 'warning',
                                __('New member') => 'primary',
                                default => 'info',
                            })
                            ->alignment(Alignment::End)
                            ->grow(false),
                    ]),
                    TextColumn::make('event.name')
                        ->label('Event')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->icon(Heroicon::OutlinedCalendarDays)
                        // The date only for an arrival on an earlier day (an
                        // overnight stay); tonight's arrivals need just the time.
                        ->formatStateUsing(fn (?string $state, Attendance $record): string => ($state ?? __('Untitled event')).' · '
                            .$record->checked_in_at->translatedFormat($record->checked_in_at->isToday() ? 'g:i A' : 'M j, g:i A')),
                ])->space(1),

                Stack::make([
                    TextColumn::make('member.skills.name')
                        ->label('Skills')
                        ->badge(),
                    TextColumn::make('member.watchlist_reason')
                        ->label('Watchlist reason')
                        ->color('warning')
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->wrap()
                        ->visible(fn (): bool => Gate::allows('view-sensitive-member-fields')),
                    TextColumn::make('watchlistProbation')
                        ->label('Watchlist probation')
                        ->color('warning')
                        ->icon(Heroicon::OutlinedEye)
                        ->getStateUsing(fn (Attendance $record): ?string => $record->member?->isOnWatchlistProbation()
                            ? __('Recently off watchlist — probation until :date', ['date' => $record->member->watchlistProbationEndsOn()->isoFormat('ll')])
                            : null),
                    TextColumn::make('visit_note')
                        ->label('Visit note')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->wrap()
                        ->visible(fn (): bool => Gate::allows('manage-visit-notes'))
                        ->action($this->editVisitNoteAction()),
                    TextColumn::make('behaviorNotesSummary')
                        ->label('Behavior notes')
                        ->icon(Heroicon::OutlinedFlag)
                        ->wrap()
                        // One line per note: joined with "\n" they'd collapse
                        // into a single run-on line in the cell.
                        ->listWithLineBreaks()
                        ->visible(fn (): bool => Gate::allows('manage-behavior-notes'))
                        ->getStateUsing(fn (Attendance $record): ?array => $this->behaviorNotesSummary($record))
                        ->action($this->addBehaviorNoteAction()),
                ])->space(1),
            ])
            ->filters([
                Filter::make('watchlist_only')
                    ->label('Watchlist only')
                    ->toggle()
                    // Includes members on watchlist probation -- the people
                    // staff were asked to keep an eye on either way.
                    ->query(function (Builder $query): Builder {
                        $days = MembershipSetting::watchlistProbationDays();

                        return $query->whereHas('member', fn ($q) => $q
                            ->where('on_watchlist', true)
                            ->when($days, fn ($q) => $q->orWhereDate('watchlist_probation_start', '>', today()->subDays($days))));
                    }),
            ])
            ->recordActions([
                $this->departAction()->button(),
                $this->departAlreadyCountedAction(),
                $this->editVisitNoteAction(),
                $this->addBehaviorNoteAction(),
            ])
            // 25, not the default 5: on a phone, paging through a busy night
            // five cards at a time makes it easy to miss someone leaving.
            ->defaultPaginationPageOption(25)
            ->poll('15s');
    }

    public function departAction(): Action
    {
        return Action::make('depart')
            ->label('Depart')
            ->requiresConfirmation()
            ->action(function (Attendance $record): void {
                // Re-checked here, not just via canAccess() gating the page —
                // same defensive pattern as grant-event-comp and
                // registerGuestAction elsewhere in this app.
                abort_unless(Gate::allows('record-departures'), 403);

                $record->update(['departed_at' => now()]);

                Notification::make()->title(__('Marked departed'))->success()->send();
            });
    }

    /**
     * For a patron who already left through the Dashboard's anonymous
     * "Record departures" count: takes them off this list without lowering
     * occupancy a second time (CapacityService::departAlreadyCounted()).
     * Shown only while their event's night has an unmatched anonymous
     * departure, re-checked in the service.
     */
    public function departAlreadyCountedAction(): Action
    {
        return Action::make('departAlreadyCounted')
            ->label('Depart (already counted)')
            ->color('gray')
            ->visible(fn (Attendance $record): bool => $this->unmatchedDeparturesOn($record->event->event_date) > 0)
            ->requiresConfirmation()
            ->modalDescription(__('Use this when someone was already counted with "Record departures" on the Dashboard. They come off this list, and the people-in-building count stays the same.'))
            ->action(function (Attendance $record): void {
                abort_unless(Gate::allows('record-departures'), 403);

                app(CapacityService::class)->departAlreadyCounted($record, Auth::user());
                $this->unmatchedDepartures = [];

                Notification::make()->title(__('Marked departed (count unchanged)'))->success()->send();
            });
    }

    /**
     * Per-request memo of CapacityService::unmatchedDepartures() by night,
     * so the action's visible() check doesn't query once per row.
     *
     * @var array<string, int>
     */
    protected array $unmatchedDepartures = [];

    protected function unmatchedDeparturesOn(CarbonInterface $date): int
    {
        return $this->unmatchedDepartures[$date->toDateString()]
            ??= app(CapacityService::class)->unmatchedDepartures($date);
    }

    /**
     * Overwritable, unlike everything else in this app -- a visit note is
     * just "what does this person look like right now," not an audit
     * record. Never surfaced anywhere outside this page; see the migration
     * that added attendance.visit_note for why that alone is sufficient to
     * satisfy "not kept after the event."
     */
    public function editVisitNoteAction(): Action
    {
        return Action::make('editVisitNote')
            ->label('Visit note')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('manage-visit-notes'))
            ->fillForm(fn (Attendance $record): array => ['visit_note' => $record->visit_note])
            ->schema([
                TextInput::make('visit_note')
                    ->label('Visit note')
                    ->helperText(__('e.g. a clothing description to help identify this patron tonight. Not kept after the event.'))
                    ->maxLength(255),
            ])
            ->action(function (Attendance $record, array $data): void {
                // Re-checked here, not just via canAccess() gating the page —
                // same defensive pattern as departAction() above.
                abort_unless(Gate::allows('manage-visit-notes'), 403);

                $record->update(['visit_note' => $data['visit_note'] ?: null]);

                Notification::make()->title(__('Visit note saved'))->success()->send();
            });
    }

    /**
     * Append-only -- no edit action exists, matching every other audit
     * ledger in this app. Visibility of what got written is handled by
     * behaviorNotesSummary(), not here.
     */
    public function addBehaviorNoteAction(): Action
    {
        return Action::make('addBehaviorNote')
            ->label('Behavior note')
            ->icon(Heroicon::OutlinedFlag)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('manage-behavior-notes'))
            ->schema([
                Textarea::make('note')->required(),
            ])
            ->action(function (Attendance $record, array $data): void {
                abort_unless(Gate::allows('manage-behavior-notes'), 403);

                $record->behaviorNotes()->create([
                    'note' => $data['note'],
                    'created_by' => Auth::id(),
                ]);

                Notification::make()->title(__('Behavior note added'))->success()->send();
            });
    }

    /**
     * Three tiers, each a superset of the one below (ordinary atLeast()
     * monotonicity -- Door inherits DM's tier the same way it inherits
     * Volunteer's record-departures gate). Manager+ sees every note's full
     * text and author. DM and Door see every note's full text but not who
     * wrote it. Everyone else (Showrunner, Volunteer) sees only their own
     * notes' full text plus a bare count of what other staff wrote -- the
     * club's call on how these should be shared among volunteers.
     *
     * Newest first, one entry per line of the cell.
     *
     * @return list<string>|null
     */
    public function behaviorNotesSummary(Attendance $record): ?array
    {
        $notes = $record->behaviorNotes;

        if ($notes->isEmpty()) {
            return null;
        }

        $notes = $notes->sortByDesc('created_at');

        if (Gate::allows('view-sensitive-member-fields')) {
            return $notes
                ->map(fn (AttendanceBehaviorNote $note): string => "{$note->note} — {$note->createdBy->name}")
                ->values()
                ->all();
        }

        if (Auth::user()->role->atLeast(Role::DM)) {
            return $notes->pluck('note')->values()->all();
        }

        $own = $notes->where('created_by', Auth::id());
        $othersCount = $notes->count() - $own->count();

        if ($own->isEmpty()) {
            return [trans_choice(':count behavior note|:count behavior notes', $notes->count())];
        }

        $lines = $own->pluck('note')->values()->all();

        if ($othersCount > 0) {
            $lines[] = __('(+:count more)', ['count' => $othersCount]);
        }

        return $lines;
    }
}
