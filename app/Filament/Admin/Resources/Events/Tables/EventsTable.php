<?php

namespace App\Filament\Admin\Resources\Events\Tables;

use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Models\Event;
use App\Models\MembershipSetting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Loaded once for the page so each row's Archive/Delete visibility
            // (Event::hasRecordedActivity(), canBeArchived()) reads flags
            // instead of running its own queries.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withExists(['attendance', 'compRequests', 'addOnDayPasses', 'banExceptions']))
            ->defaultSort('event_date', 'desc')
            ->columns([
                TextColumn::make('event_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->time()
                    ->toggleable(),
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (Event $record): ?string => $record->isArchived() ? __('Archived') : null),
                TextColumn::make('eventType.name')
                    ->label('Event type')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entry_fee')
                    ->money()
                    ->sortable(),
                TextColumn::make('pool_fee')
                    ->money()
                    ->sortable()
                    ->visible(fn (): bool => MembershipSetting::current()->pool_enabled),
                // Arrivals only: a prepay who hasn't come through the door
                // yet isn't counted.
                TextColumn::make('attendance_count')
                    ->label('Arrived')
                    ->counts(['attendance' => fn (Builder $query): Builder => $query->whereNotNull('checked_in_at')])
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('comp_list_due_at')
                    ->label('Comp list due')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('comp_list_overdue')
                    ->label('Comp List Overdue')
                    ->boolean()
                    ->state(fn (Event $record) => $record->isCompListOverdue())
                    ->tooltip(fn (bool $state): string => $state ? __('Comp list overdue') : __('Comp list not overdue'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('createdBy.name')
                    ->label('Created by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('event_type_id')
                    ->label('Event type')
                    ->relationship('eventType', 'name'),
                // "Upcoming" means not over yet, the same test as
                // Event::currentOrFutureQuery(): today or later, or still
                // running past midnight per ends_at.
                SelectFilter::make('when')
                    ->label('When')
                    ->options([
                        'upcoming' => __('Upcoming'),
                        'past' => __('Past'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'upcoming' => $query->where(fn (Builder $upcoming) => $upcoming
                            ->whereDate('event_date', '>=', today())
                            ->orWhere('ends_at', '>=', now())),
                        'past' => $query->whereDate('event_date', '<', today())
                            ->where(fn (Builder $over) => $over->whereNull('ends_at')->orWhere('ends_at', '<', now())),
                        default => $query,
                    }),
                // Archived events drop out of the default view but stay one
                // click away -- archiving retires an event, it doesn't hide
                // its history.
                TernaryFilter::make('archived')
                    ->label('Archived')
                    ->placeholder(__('All events'))
                    ->trueLabel(__('Archived only'))
                    ->falseLabel(__('Active only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                        blank: fn (Builder $query): Builder => $query,
                    )
                    ->default(false),
            ])
            ->recordActions([
                EditAction::make(),
                static::duplicateAction(),
                static::archiveAction(),
                static::unarchiveAction(),
                // EventPolicy::delete() only allows an event nothing points
                // at, so this appears only for an unused one.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Each selected row goes through EventPolicy::delete(), so
                    // events with history are skipped instead of hitting their
                    // foreign keys -- same pattern as CategoriesTable.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }

    /**
     * Retires an event: out of the check-in desk, Active Patrons, the
     * Showrunner screen and the default list, with everything recorded
     * against it kept. EventPolicy::archive() allows it for a past event,
     * or an upcoming one nobody is on yet. Shared with EditEvent's header.
     */
    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label('Archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Archive this event?'))
            ->modalDescription(fn (Event $record): string => $record->hasEnded()
                ? __('It moves out of the events list, and everything recorded for it is kept. You can unarchive it at any time.')
                : __("It hasn't happened yet: archiving takes it off the check-in desk and the Showrunner screen. You can unarchive it at any time."))
            ->visible(fn (Event $record): bool => Gate::allows('archive', $record))
            ->action(function (Event $record): void {
                abort_unless(Gate::allows('archive', $record), 403);

                $record->archive(Auth::user());

                Notification::make()->title(__('Event archived'))->success()->send();
            });
    }

    public static function unarchiveAction(): Action
    {
        return Action::make('unarchive')
            ->label('Unarchive')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Event $record): bool => Gate::allows('restore', $record))
            ->action(function (Event $record): void {
                abort_unless(Gate::allows('restore', $record), 403);

                $record->unarchive();

                Notification::make()->title(__('Event unarchived'))->success()->send();
            });
    }

    /**
     * Starts a new event from an existing one — reuses EventForm as-is so the
     * modal never drifts from the plain create form. event_date/starts_at/
     * ends_at are deliberately left blank (EventForm's own ->required() rules
     * force a real date before it can save). created_by is set explicitly to
     * whoever duplicated it, not the original creator — a schema filled via
     * ->fillForm() with an explicit array skips per-field ->default() entirely
     * (Filament only applies component defaults when filling with null), so
     * EventForm's own Hidden('created_by')->default(...) never fires here.
     */
    protected static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->schema(fn (Schema $schema): Schema => EventForm::configure($schema, includeSummary: false, includeAddOnSelection: false))
            ->fillForm(fn (Event $record): array => [
                ...$record->only([
                    'name', 'event_type_id', 'entry_fee',
                    'door_prepay_enabled', 'showrunner_id', 'host_id', 'notes',
                ]),
                // Never blindly carries a nonzero pool_fee onto a new event
                // while pool is disabled -- the field's hidden on the form
                // itself, but fillForm() bypasses that, so it needs its own
                // explicit check.
                'pool_fee' => MembershipSetting::current()->pool_enabled ? $record->pool_fee : 0,
                'created_by' => Auth::id(),
            ])
            ->action(function (array $data, Event $record): void {
                $event = Event::create($data);
                // add_on_ids isn't in the schema here (includeAddOnSelection:
                // false above) -- copy the source's bindings directly instead
                // of relying on the field, which wouldn't save through this
                // plain Action anyway. See EventForm::configure()'s own
                // comment on $includeAddOnSelection.
                $event->addOns()->sync($record->addOns->pluck('id'));

                Notification::make()
                    ->title(__('Event duplicated'))
                    ->success()
                    ->send();
            })
            ->visible(fn (): bool => Gate::allows('create', Event::class));
    }
}
