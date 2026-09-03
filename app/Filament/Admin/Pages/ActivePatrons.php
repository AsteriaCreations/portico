<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\AttendanceBehaviorNote;
use App\Models\Event;
use App\Models\User;
use App\Services\CapacityService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

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

    protected string $view = 'filament.admin.pages.active-patrons';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Active Patrons';

    public static function canAccess(): bool
    {
        return Gate::allows('record-departures');
    }

    public function getActiveEvents(): Collection
    {
        return Event::currentQuery()->orderBy('event_date')->get();
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
        return User::signedInStaffQuery()->orderBy('name')->get();
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
                ->with(['addOns', 'behaviorNotes.createdBy', 'member.skills']))
            ->columns([
                TextColumn::make('member.username')
                    ->label('Member')
                    ->action($this->departAction()),
                TextColumn::make('event.name')
                    ->label('Event'),
                TextColumn::make('checked_in_at')
                    ->label('Checked in')
                    ->dateTime('M j, g:i A'),
                TextColumn::make('member.skills.name')
                    ->label('Skills')
                    ->badge()
                    ->placeholder('—'),
                IconColumn::make('overnight')
                    ->label('Overnight')
                    ->boolean()
                    ->getStateUsing(fn (Attendance $record): bool => $record->isOvernightStay())
                    ->tooltip(fn (bool $state): string => $state ? 'Staying overnight' : 'Same-night guest'),
                IconColumn::make('member.on_watchlist')
                    ->label('Watchlist')
                    ->boolean()
                    ->color(fn (?bool $state): string => $state ? 'warning' : 'gray')
                    ->tooltip(fn (?bool $state): string => $state ? 'On watchlist' : 'Not on watchlist'),
                TextColumn::make('member.watchlist_reason')
                    ->label('Watchlist reason')
                    ->visible(fn (): bool => Gate::allows('view-sensitive-member-fields')),
                TextColumn::make('visit_note')
                    ->label('Visit note')
                    ->placeholder('— add —')
                    ->visible(fn (): bool => Gate::allows('manage-visit-notes'))
                    ->action($this->editVisitNoteAction()),
                TextColumn::make('behaviorNotesSummary')
                    ->label('Behavior notes')
                    ->placeholder('— add —')
                    ->wrap()
                    ->visible(fn (): bool => Gate::allows('manage-behavior-notes'))
                    ->getStateUsing(fn (Attendance $record): ?string => $this->behaviorNotesSummary($record))
                    ->action($this->addBehaviorNoteAction()),
            ])
            ->filters([
                Filter::make('watchlist_only')
                    ->label('Watchlist only')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('member', fn ($q) => $q->where('on_watchlist', true))),
            ])
            ->recordActions([$this->departAction()])
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

                Notification::make()->title('Marked departed')->success()->send();
            });
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
            ->fillForm(fn (Attendance $record): array => ['visit_note' => $record->visit_note])
            ->schema([
                TextInput::make('visit_note')
                    ->label('Visit note')
                    ->helperText('e.g. a clothing description to help identify this patron tonight. Not kept after the event.')
                    ->maxLength(255),
            ])
            ->action(function (Attendance $record, array $data): void {
                // Re-checked here, not just via canAccess() gating the page —
                // same defensive pattern as departAction() above.
                abort_unless(Gate::allows('manage-visit-notes'), 403);

                $record->update(['visit_note' => $data['visit_note'] ?: null]);

                Notification::make()->title('Visit note saved')->success()->send();
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
            ->schema([
                Textarea::make('note')->required(),
            ])
            ->action(function (Attendance $record, array $data): void {
                abort_unless(Gate::allows('manage-behavior-notes'), 403);

                $record->behaviorNotes()->create([
                    'note' => $data['note'],
                    'created_by' => Auth::id(),
                ]);

                Notification::make()->title('Behavior note added')->success()->send();
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
     */
    public function behaviorNotesSummary(Attendance $record): ?string
    {
        $notes = $record->behaviorNotes;

        if ($notes->isEmpty()) {
            return null;
        }

        $notes = $notes->sortByDesc('created_at');

        if (Gate::allows('view-sensitive-member-fields')) {
            return $notes
                ->map(fn (AttendanceBehaviorNote $note): string => "{$note->note} — {$note->createdBy->name}")
                ->implode("\n");
        }

        if (Auth::user()->role->atLeast(Role::DM)) {
            return $notes->pluck('note')->implode("\n");
        }

        $own = $notes->where('created_by', Auth::id());
        $othersCount = $notes->count() - $own->count();

        if ($own->isEmpty()) {
            return $notes->count().' behavior note'.($notes->count() === 1 ? '' : 's');
        }

        $summary = $own->pluck('note')->implode("\n");

        return $othersCount > 0 ? "{$summary}\n(+{$othersCount} more)" : $summary;
    }
}
