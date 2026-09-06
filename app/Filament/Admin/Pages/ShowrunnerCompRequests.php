<?php

namespace App\Filament\Admin\Pages;

use App\Enums\CompRequestStatus;
use App\Enums\Role;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Models\Attendance;
use App\Models\CompReason;
use App\Models\CompRequest;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification as LaravelNotification;

/**
 * A showrunner's own scoped view onto the comp list — they can nominate a
 * member for a comp on the one event they're assigned to, but the request
 * sits pending until an Admin+ approves it via CompRequestsRelationManager.
 * See docs/BLUEPRINT.md "Showrunners".
 */
class ShowrunnerCompRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.showrunner-comp-requests';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Comp Requests';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    // A standalone, narrow capability tied to the Showrunner role rather
    // than a rank threshold — the same non-monotonic shape as the
    // Manager/Owner subscription perk gate.
    public static function canAccess(): bool
    {
        return auth()->user()?->role === Role::Showrunner
            && MembershipSetting::current()->showrunner_comp_requests_enabled;
    }

    public function mount(): void
    {
        $this->form->fill([
            'event_id' => static::eventOptionsQuery()->orderByDesc('event_date')->value('id'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('event_id')
                    ->label('Event')
                    ->live()
                    ->options(fn () => static::eventOptionsQuery()
                        ->orderByDesc('event_date')
                        ->get()
                        ->mapWithKeys(fn (Event $event) => [$event->id => static::eventLabel($event)])
                        ->all())
                    ->required(),
            ]);
    }

    public function getSelectedEvent(): ?Event
    {
        $id = $this->data['event_id'] ?? null;

        return $id ? static::eventOptionsQuery()->find($id) : null;
    }

    public function requestCompAction(): Action
    {
        return Action::make('requestComp')
            ->label('Request a comp')
            ->schema([
                Select::make('member_id')
                    ->label('Member')
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search) {
                        $event = $this->getSelectedEvent();

                        return Member::query()
                            ->where(fn ($query) => $query->matchingSearch($search))
                            ->where(fn ($query) => $query
                                // "Not currently banned" -- excludes a
                                // permanent ban or an active suspension, but
                                // not one whose banned_until has passed. See
                                // Member::isCurrentlyBanned().
                                ->where(fn ($query) => $query
                                    ->where('is_banned', false)
                                    ->orWhere(fn ($query) => $query->whereNotNull('banned_until')->whereDate('banned_until', '<=', today())))
                                ->when($event, fn ($query) => $query->orWhereHas(
                                    'banExceptions',
                                    fn ($query) => $query->where('event_id', $event->id)
                                )))
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Member $record) => [$record->id => Member::pickerLabel($record)])
                            ->all();
                    })
                    ->getOptionLabelUsing(fn ($value) => ($record = Member::find($value)) ? Member::pickerLabel($record) : null)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $event = $this->getSelectedEvent();
                        if (! $event) {
                            $fail('Select an event first.');

                            return;
                        }

                        $member = Member::find($value);
                        if ($member?->isCurrentlyBanned() && ! $member->hasBanExceptionFor($event)) {
                            $fail('This member is banned and has no exception for this event.');

                            return;
                        }

                        if (Attendance::where('event_id', $event->id)->where('member_id', $value)->exists()) {
                            $fail('This member already has an attendance record for this event.');
                        }

                        if (CompRequest::where('event_id', $event->id)->where('member_id', $value)->where('status', CompRequestStatus::Pending)->exists()) {
                            $fail('This member already has a pending comp request for this event.');
                        }
                    })
                    ->required(),
                Checkbox::make('other_reason')
                    ->label("Reason isn't in the list")
                    ->live(),
                Select::make('comp_reason_id')
                    ->label('Reason')
                    ->options(fn () => CompReason::where('active', true)->orderBy('sort_order')->pluck('name', 'id'))
                    ->required(fn (Get $get): bool => ! $get('other_reason'))
                    ->visible(fn (Get $get): bool => ! $get('other_reason')),
                TextInput::make('requested_reason_text')
                    ->label('Reason')
                    ->maxLength(255)
                    ->helperText('Admin will approve, reject, or turn this into a real reason.')
                    ->required(fn (Get $get): bool => (bool) $get('other_reason'))
                    ->visible(fn (Get $get): bool => (bool) $get('other_reason')),
                Textarea::make('notes')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ])
            ->visible(fn (): bool => (bool) $this->getSelectedEvent())
            ->action(function (array $data): void {
                $user = auth()->user();
                $event = $this->getSelectedEvent();

                // Re-verified here, not just via the scoped Select options —
                // same defensive pattern as registerGuestAction/checkInAction
                // elsewhere in this app.
                abort_unless($event && $user->member_id && (int) $event->showrunner_id === (int) $user->member_id, 403);

                $otherReason = (bool) ($data['other_reason'] ?? false);

                $request = CompRequest::create([
                    'event_id' => $event->id,
                    'member_id' => $data['member_id'],
                    'comp_reason_id' => $otherReason ? null : $data['comp_reason_id'],
                    'requested_reason_text' => $otherReason ? $data['requested_reason_text'] : null,
                    'requested_by' => $user->id,
                    'notes' => $data['notes'] ?? null,
                    'status' => CompRequestStatus::Pending,
                ]);

                static::notifyAdminsOfNewRequest($request, $event);

                Notification::make()->title('Comp request submitted — pending Admin approval')->success()->send();
            });
    }

    protected static function notifyAdminsOfNewRequest(CompRequest $request, Event $event): void
    {
        $admins = User::query()->where('active', true)->get()
            ->filter(fn (User $user) => $user->role->atLeast(Role::Admin));

        if ($admins->isEmpty()) {
            return;
        }

        $notification = Notification::make()
            ->title('New comp request awaiting approval')
            ->body($request->member->username.' — '.$event->event_date->toFormattedDateString())
            ->actions([
                Action::make('view')
                    ->label('Review')
                    ->url(EventResource::getUrl('edit', ['record' => $event]))
                    ->markAsRead(),
            ]);

        // Filament\Notifications\DatabaseNotification implements ShouldQueue,
        // so Notification::sendToDatabase() always dispatches a queued job —
        // it only looked synchronous under Pest because phpunit.xml forces
        // QUEUE_CONNECTION=sync there. A production install using the database
        // queue driver with no worker (this app runs no queue worker beyond
        // sync) would leave that job unprocessed forever. sendNow() bypasses
        // ShouldQueue and delivers immediately, same as everywhere else in
        // this app that's documented as "no queue worker beyond sync."
        LaravelNotification::sendNow($admins, $notification->toDatabase());
    }

    public function table(Table $table): Table
    {
        $event = $this->getSelectedEvent();

        return $table
            ->query(CompRequest::query()->where('event_id', $event?->id ?? 0))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member.username')->label('Member'),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (CompRequest $record) => $record->comp_reason_id
                        ? $record->compReason->name
                        : "{$record->requested_reason_text} (freeform)"),
                TextColumn::make('status')->badge(),
                TextColumn::make('notes'),
                TextColumn::make('reviewedBy.name')->label('Reviewed by'),
                TextColumn::make('reviewed_at')->dateTime(),
                TextColumn::make('review_notes')->label('Review notes'),
            ]);
    }

    protected static function eventOptionsQuery(): Builder
    {
        $memberId = auth()->user()?->member_id;

        return Event::currentOrFutureQuery()->where('showrunner_id', $memberId ?? 0);
    }

    protected static function eventLabel(Event $event): string
    {
        return $event->event_date->toFormattedDateString().' — '.($event->name ?? 'Untitled event');
    }
}
