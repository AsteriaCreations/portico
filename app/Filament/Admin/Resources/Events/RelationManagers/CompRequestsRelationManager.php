<?php

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Enums\CompRequestStatus;
use App\Models\Attendance;
use App\Models\CompReason;
use App\Models\CompRequest;
use App\Services\CapacityService;
use App\Services\PricingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The Admin+ approval queue for showrunner-submitted comp requests (see
 * ShowrunnerCompRequests). Approving mirrors CompListRelationManager's
 * create flow exactly — same capacity gate, same PricingService call — so
 * an approved request becomes an ordinary comped Attendance row and shows up
 * on the existing Comp List tab and the check-in page's back-check-in table
 * automatically. Manager+ can view the queue; only Admin+ sees the
 * Approve/Reject buttons, per CompRequestPolicy::update().
 */
class CompRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'compRequests';

    protected static ?string $title = 'Comp requests';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member.username')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (CompRequest $record) => $record->comp_reason_id
                        ? $record->compReason->name
                        : "{$record->requested_reason_text} (freeform)"),
                TextColumn::make('requestedBy.name')
                    ->label('Requested by'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('notes')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewedBy.name')
                    ->label('Reviewed by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reviewed_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('review_notes')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                $this->approveAction(),
                $this->rejectAction(),
            ]);
    }

    protected function approveAction(): Action
    {
        return Action::make('approve')
            ->visible(fn (CompRequest $record): bool => $record->status === CompRequestStatus::Pending
                && Auth::user()->can('update', $record)
                && ! ($record->member->isCurrentlyBanned() && ! $record->member->hasBanExceptionFor($record->event)))
            ->requiresConfirmation()
            // Only a freeform request (no comp_reason_id yet) needs input here
            // — Admin resolves it to an existing CompReason or creates a new
            // one on the fly (createOptionForm/createOptionUsing), which is
            // exactly what "modify it into a real category" means. A request
            // that already named a real reason just confirms, unchanged.
            ->schema(fn (CompRequest $record): array => $record->comp_reason_id === null ? [
                Select::make('comp_reason_id')
                    ->label('Resolve to reason')
                    ->helperText("Showrunner requested: \"{$record->requested_reason_text}\"")
                    ->options(fn () => CompReason::where('active', true)->orderBy('sort_order')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->createOptionForm([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(60),
                    ])
                    ->createOptionUsing(fn (array $data): int => CompReason::create($data)->id),
            ] : [])
            ->action(function (CompRequest $record, array $data): void {
                abort_unless(Auth::user()->can('update', $record), 403);
                abort_unless($record->status === CompRequestStatus::Pending, 409);

                $event = $record->event;
                abort_unless(app(CapacityService::class)->hasRoom($event->event_date), 403);
                abort_if($record->member->isCurrentlyBanned() && ! $record->member->hasBanExceptionFor($event), 403);

                $compReasonId = $record->comp_reason_id ?? ($data['comp_reason_id'] ?? null);
                abort_if($compReasonId === null, 422);

                $breakdown = app(PricingService::class)->applyEventComp(
                    app(PricingService::class)->price($record->member, $event)
                );

                $attendance = Attendance::create([
                    'member_id' => $record->member_id,
                    'event_id' => $event->id,
                    'checked_in_by' => Auth::id(),
                    'checked_in_at' => null,
                    'comp_reason_id' => $compReasonId,
                    'notes' => $record->notes,
                    ...$breakdown->toAttendanceAttributes(),
                ]);

                $record->update([
                    'comp_reason_id' => $compReasonId,
                    'status' => CompRequestStatus::Approved,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                    'attendance_id' => $attendance->id,
                ]);

                Notification::make()->title('Comp request approved')->success()->send();
            });
    }

    protected function rejectAction(): Action
    {
        return Action::make('reject')
            ->visible(fn (CompRequest $record): bool => $record->status === CompRequestStatus::Pending
                && Auth::user()->can('update', $record))
            ->schema([
                Textarea::make('review_notes')
                    ->label('Reason (optional)')
                    ->maxLength(255),
            ])
            ->action(function (CompRequest $record, array $data): void {
                abort_unless(Auth::user()->can('update', $record), 403);
                abort_unless($record->status === CompRequestStatus::Pending, 409);

                $record->update([
                    'status' => CompRequestStatus::Rejected,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                    'review_notes' => $data['review_notes'] ?? null,
                ]);

                Notification::make()->title('Comp request rejected')->success()->send();
            });
    }
}
