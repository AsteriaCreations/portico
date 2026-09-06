<?php

namespace App\Filament\Admin\Resources\Events\Schemas;

use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Services\EventSummaryService;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
     * render while duplicating it into a brand new, blank event.
     */
    public static function configure(Schema $schema, bool $includeSummary = true): Schema
    {
        return $schema
            ->components([
                DatePicker::make('event_date')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (blank($state)) {
                            return;
                        }

                        $time = filled($get('starts_at')) ? Carbon::parse($get('starts_at'))->format('H:i:s') : '00:00:00';
                        $set('starts_at', Carbon::parse($state)->setTimeFromTimeString($time)->toDateTimeString());
                    }),
                DateTimePicker::make('starts_at')
                    ->helperText("Date defaults from the event date above — an event can't start on a different day.")
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->helperText('Set independently — pick the next day for an event that runs past midnight.')
                    ->required()
                    ->after('starts_at'),
                DatePicker::make('comp_list_due_at')
                    ->label('Comp list due date')
                    ->helperText('Optional reminder date for staff to finalize Comp List additions by. Informational only — nothing is blocked once it passes.')
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
                    ->helperText('0 for a pool-only event.')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->default(0),
                TextInput::make('pool_fee')
                    ->label('Pool fee')
                    ->helperText('0 if the pool is closed / not offered.')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->default(0)
                    ->visible(fn (): bool => MembershipSetting::current()->pool_enabled),
                Toggle::make('door_prepay_enabled')
                    ->label('Allow prepay ahead of the door')
                    ->helperText('Lets the check-in page surface this event before its date, so the desk can take a walk-in prepayment for it.')
                    ->visible(fn (): bool => MembershipSetting::current()->prepay_enabled),
                Select::make('showrunner_id')
                    ->label('Showrunner')
                    ->relationship('showrunner', 'username')
                    ->getOptionLabelFromRecordUsing(fn (Member $record) => Member::pickerLabel($record))
                    ->searchable(Member::searchableColumns())
                    ->preload()
                    ->helperText('The member running this event — lets their linked login submit comp-list requests for it, subject to Admin+ approval.'),
                Select::make('host_id')
                    ->label('Host')
                    ->relationship('host', 'username')
                    ->getOptionLabelFromRecordUsing(fn (Member $record) => Member::pickerLabel($record))
                    ->searchable(Member::searchableColumns())
                    ->preload()
                    ->helperText('Automatically checked in free of charge when they attend this event — no comp request needed.'),
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
                    Section::make('Summary')
                        ->visible(fn (?Event $record): bool => $record?->hasEnded() ?? false)
                        ->components([
                            Placeholder::make('summary_checked_in')
                                ->label('Checked in')
                                ->content(fn (?Event $record, EventSummaryService $summaryService): string => (string) ($record ? $summaryService->forEvent($record)['checked_in'] : 0)),
                            Placeholder::make('summary_prepaid_no_show')
                                ->label('Prepaid, never arrived')
                                ->content(fn (?Event $record, EventSummaryService $summaryService): string => (string) ($record ? $summaryService->forEvent($record)['prepaid_no_show'] : 0)),
                            Placeholder::make('summary_revenue')
                                ->label('Revenue')
                                ->content(fn (?Event $record, EventSummaryService $summaryService): string => '$'.number_format($record ? $summaryService->forEvent($record)['revenue'] : 0, 2)),
                        ]),
                ] : []),
            ]);
    }
}
