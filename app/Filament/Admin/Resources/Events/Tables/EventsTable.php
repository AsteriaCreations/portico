<?php

namespace App\Filament\Admin\Resources\Events\Tables;

use App\Filament\Admin\Resources\Events\Schemas\EventForm;
use App\Models\Event;
use App\Models\MembershipSetting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('event_date', 'desc')
            ->columns([
                TextColumn::make('event_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable(),
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
                TextColumn::make('comp_list_due_at')
                    ->label('Comp list due')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('comp_list_overdue')
                    ->label('Comp List Overdue')
                    ->boolean()
                    ->state(fn (Event $record) => $record->isCompListOverdue())
                    ->tooltip(fn (bool $state): string => $state ? 'Comp list overdue' : 'Comp list not overdue')
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
            ])
            ->recordActions([
                EditAction::make(),
                static::duplicateAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
            ->schema(fn (Schema $schema): Schema => EventForm::configure($schema, includeSummary: false))
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
            ->action(function (array $data): void {
                Event::create($data);

                Notification::make()
                    ->title('Event duplicated')
                    ->success()
                    ->send();
            })
            ->visible(fn (): bool => Gate::allows('create', Event::class));
    }
}
