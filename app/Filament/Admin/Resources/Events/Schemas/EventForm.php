<?php

namespace App\Filament\Admin\Resources\Events\Schemas;

use App\Enums\EventRepeat;
use App\Models\AddOn;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Services\EventDuplicator;
use App\Services\EventSummaryService;
use App\Support\Cents;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class EventForm
{
    /**
     * $includeSummary is false for EventsTable::duplicateAction(), which
     * reuses this same schema on a fresh Action -- Filament injects the
     * table row's own record into that modal too (Action::getRecord()), so
     * without this flag an ended event's Summary section would confusingly
     * render while duplicating it into a brand new, blank event. The same
     * goes for the "already recorded" warning at the top of the form.
     *
     * $includeAddOnSelection is also false there, for a different reason: a
     * multiple ->relationship() Select is unconditionally dehydrated(false)
     * (Filament's own Select::relationship(), regardless of context), so it
     * only ever saves via a page's saveRelationships() lifecycle hook
     * (CreateRecord/EditRecord) -- duplicateAction()'s plain Action::make()
     * has no such hook, so the field would render but silently discard
     * whatever the user picks. duplicateAction() instead copies the source
     * event's current add-ons onto the new one directly, unconditionally.
     *
     * $includeRepeat adds duplicateAction()'s Repeat fields, which
     * EventDuplicator turns into dates; they mean nothing on create/edit.
     */
    public static function configure(Schema $schema, bool $includeSummary = true, bool $includeAddOnSelection = true, bool $includeRepeat = false): Schema
    {
        return $schema
            ->components([
                // Attendance rows are price snapshots, so editing the date or
                // fees now changes nothing already recorded -- say so before
                // someone expects it to.
                ...($includeSummary ? [
                    Callout::make(fn (?Event $record): string => __('Archived on :date by :name.', [
                        'date' => $record?->archived_at?->isoFormat('ll') ?? '',
                        'name' => $record?->archivedBy?->name ?? __('an unknown user'),
                    ]))
                        ->description(__('This event is read-only and hidden from the check-in desk. Unarchive it to make changes.'))
                        ->info()
                        ->columnSpanFull()
                        ->visible(fn (?Event $record): bool => $record?->isArchived() ?? false),
                    Callout::make(fn (?Event $record): string => trans_choice(
                        ':count person is already recorded for this event.|:count people are already recorded for this event.',
                        static::recordedCount($record),
                    ))
                        ->description(__("Changing the fees won't change what they paid, and moving the date won't move which month's subscription covered them."))
                        ->warning()
                        ->columnSpanFull()
                        // A warning about editing: ViewEvent (Managers) can't
                        // change the date or fees, so it doesn't apply there.
                        ->hiddenOn('view')
                        ->visible(fn (?Event $record): bool => ! ($record?->isArchived() ?? false) && static::recordedCount($record) > 0),
                ] : []),
                DatePicker::make('event_date')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (blank($state)) {
                            return;
                        }

                        $oldStart = filled($get('starts_at')) ? Carbon::parse($get('starts_at')) : null;
                        $newStart = Carbon::parse($state)->setTimeFromTimeString($oldStart?->format('H:i:s') ?? '00:00:00');
                        $set('starts_at', $newStart->toDateTimeString());

                        // Keep the event's length, so moving (or duplicating)
                        // an overnight event still ends it the next day.
                        if ($oldStart && filled($get('ends_at'))) {
                            $length = (int) $oldStart->diffInSeconds(Carbon::parse($get('ends_at')), false);
                            $set('ends_at', $newStart->copy()->addSeconds($length)->toDateTimeString());
                        }

                        if ($oldStart && filled($get('checkin_closes_at'))) {
                            $offset = (int) $oldStart->diffInSeconds(Carbon::parse($get('checkin_closes_at')), false);
                            $set('checkin_closes_at', $newStart->copy()->addSeconds($offset)->toDateTimeString());
                        }
                    }),
                DateTimePicker::make('starts_at')
                    ->helperText(__("Date defaults from the event date above — an event can't start on a different day."))
                    ->required()
                    // The desk finds tonight's events by event_date OR the
                    // starts_at/ends_at window, so a start on another day
                    // would surface the event on two different nights.
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if (blank($value) || blank($get('event_date'))) {
                            return;
                        }

                        if (! Carbon::parse($value)->isSameDay(Carbon::parse($get('event_date')))) {
                            $fail(__('The start must be on the event date.'));
                        }
                    }),
                DateTimePicker::make('ends_at')
                    ->helperText(__('Pick the next day for an event that runs past midnight. Changing the event date moves this too, keeping the same length.'))
                    ->required()
                    ->after('starts_at')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (blank($state) || blank($get('starts_at'))) {
                            return;
                        }

                        $set('checkin_closes_at', Event::defaultCheckInClose(Carbon::parse($get('starts_at')), Carbon::parse($state))->toDateTimeString());
                    }),
                DateTimePicker::make('checkin_closes_at')
                    ->label('Check-in closes at')
                    ->helperText(fn (): string => __('No new check-ins at the desk or kiosk after this. People already on the Prepay or Comp List can still be marked arrived. Defaults to :overnight minutes before the end for an event that runs past midnight, :sameDay minutes otherwise; leave blank for that default.', [
                        'overnight' => MembershipSetting::current()->checkin_close_minutes_overnight,
                        'sameDay' => MembershipSetting::current()->checkin_close_minutes_same_day,
                    ]))
                    ->afterOrEqual('starts_at'),
                ...($includeRepeat ? [
                    Select::make('repeat')
                        ->options(EventRepeat::class)
                        ->default(EventRepeat::Once->value)
                        ->selectablePlaceholder(false)
                        ->required()
                        ->live()
                        ->helperText(__('Repeats start from the event date above. A date that already has this event (same name and type) is skipped.')),
                    TextInput::make('occurrences')
                        ->label('Number of events')
                        ->numeric()
                        ->integer()
                        ->minValue(2)
                        ->maxValue(EventDuplicator::MAX_OCCURRENCES)
                        ->default(4)
                        ->required(fn (Get $get): bool => static::repeats($get))
                        ->visible(fn (Get $get): bool => static::repeats($get)),
                ] : []),
                DatePicker::make('comp_list_due_at')
                    ->label('Comp list due date')
                    ->helperText(__('Optional reminder date for staff to finalize Comp List additions by. Informational only — nothing is blocked once it passes.'))
                    ->default(null),
                TextInput::make('name')
                    ->maxLength(80)
                    ->default(null),
                Select::make('event_type_id')
                    ->label('Event type')
                    ->relationship('eventType', 'name')
                    ->searchable()
                    ->preload()
                    ->default(null),
                TextInput::make('entry_fee')
                    ->label('Entry fee')
                    ->helperText(__('0 for a pool-only event.'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->rule('decimal:0,2')
                    ->prefix('$')
                    ->default(0),
                TextInput::make('comped_entry_fee')
                    ->label('Staff entry fee')
                    ->helperText(__('Leave blank for free entry. Set an amount to charge comped categories (Owner, Manager, Emeritus, Staff) a reduced entry at this event.'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->rule('decimal:0,2')
                    ->prefix('$')
                    ->default(null),
                TextInput::make('pool_fee')
                    ->label('Pool fee')
                    ->helperText(__('0 if the pool is closed / not offered.'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->rule('decimal:0,2')
                    ->prefix('$')
                    ->default(0)
                    ->visible(fn (): bool => MembershipSetting::current()->pool_enabled),
                TextInput::make('workday_voucher_amount')
                    ->label('Workday voucher')
                    ->helperText(__('Leave blank for an ordinary event. Set an amount (with minimum hours) to make this a workday: everyone who stays at least that long gets this voucher once the event ends.'))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->rule('decimal:0,2')
                    ->prefix('$')
                    ->requiredWith('workday_min_hours')
                    ->default(null),
                TextInput::make('workday_min_hours')
                    ->label('Workday minimum hours')
                    ->helperText(__('Hours from check-in to departure. Record each worker\'s departure on Active Patrons, or they get no voucher.'))
                    ->numeric()
                    ->minValue(0.25)
                    ->maxValue(24)
                    ->step(0.25)
                    ->rule('decimal:0,2')
                    ->requiredWith('workday_voucher_amount')
                    ->default(null),
                Toggle::make('door_prepay_enabled')
                    ->label('Allow prepay ahead of the door')
                    ->helperText(__('Lets the check-in page surface this event before its date, so the desk can take a walk-in prepayment for it.'))
                    ->visible(fn (): bool => MembershipSetting::current()->prepay_enabled),
                Select::make('showrunner_id')
                    ->label('Showrunner')
                    ->relationship('showrunner', 'username')
                    ->getOptionLabelFromRecordUsing(fn (Member $record) => Member::pickerLabel($record))
                    ->searchable(Member::searchableColumns())
                    ->preload()
                    ->helperText(__('The member running this event — lets their linked login submit comp-list requests for it, subject to Admin+ approval.')),
                Select::make('host_id')
                    ->label('Host')
                    ->relationship('host', 'username')
                    ->getOptionLabelFromRecordUsing(fn (Member $record) => Member::pickerLabel($record))
                    ->searchable(Member::searchableColumns())
                    ->preload()
                    ->helperText(__('Automatically checked in free of charge when they attend this event — no comp request needed.')),
                Select::make('add_on_ids')
                    ->label('Available add-ons')
                    ->relationship('addOns', 'name')
                    ->multiple()
                    ->preload()
                    // Only flat, non-subscribable add-ons (room rental,
                    // sleepover, …) -- Pool's per-event availability is
                    // already governed by pool_fee above, so it's never a
                    // candidate here.
                    ->options(fn () => AddOn::where('active', true)->where('subscribable', false)->orderBy('sort_order')->pluck('name', 'id'))
                    ->helperText(__('Which flat, checkbox-style add-ons the check-in desk can offer for this event.'))
                    ->visible(fn (): bool => $includeAddOnSelection && MembershipSetting::current()->add_ons_enabled),
                TextInput::make('notes')
                    ->maxLength(255)
                    ->default(null),
                Hidden::make('created_by')
                    ->default(fn () => Auth::id()),
                // Live-computed via EventSummaryService, not stored -- the same
                // numbers NotifyEventEnded emails to the Owner(s)/Showrunner,
                // so this can never drift from what actually went out. No new
                // gate: whoever can already reach this page sees it.
                ...($includeSummary ? [
                    Section::make(__('Summary'))
                        ->visible(fn (?Event $record): bool => $record?->hasEnded() ?? false)
                        // One forEvent() call feeds all three rows, rather
                        // than each Placeholder re-running the same queries.
                        ->components(function (?Event $record, EventSummaryService $summaryService): array {
                            $summary = $record
                                ? $summaryService->forEvent($record)
                                : ['checked_in' => 0, 'prepaid_no_show' => 0, 'revenue_cents' => 0];

                            return [
                                Placeholder::make('summary_checked_in')
                                    ->label('Checked in')
                                    ->content((string) $summary['checked_in']),
                                Placeholder::make('summary_prepaid_no_show')
                                    ->label('Prepaid, never arrived')
                                    ->content((string) $summary['prepaid_no_show']),
                                Placeholder::make('summary_revenue')
                                    ->label('Revenue')
                                    ->content(MembershipSetting::formatMoney(Cents::toFloat($summary['revenue_cents']))),
                            ];
                        }),
                ] : []),
            ]);
    }

    private static function repeats(Get $get): bool
    {
        $repeat = $get('repeat');

        return filled($repeat) && ($repeat instanceof EventRepeat ? $repeat : EventRepeat::from($repeat)) !== EventRepeat::Once;
    }

    /**
     * Attendance rows (prepaid or arrived) already recorded for an existing
     * event; 0 on the create form.
     */
    private static function recordedCount(?Event $record): int
    {
        return $record?->exists ? $record->attendance()->count() : 0;
    }
}
