<?php

namespace App\Filament\Concerns;

use App\Models\Attendance;
use App\Models\AttendanceBehaviorNote;
use App\Services\BehaviorNoteRetractionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The "Retract" actions for behavior notes and the column that shows a
 * retraction. The rules live in BehaviorNoteRetractionService (via the
 * retract-behavior-note gate); these only collect the reason and re-check
 * the gate server-side.
 */
trait RetractsBehaviorNotes
{
    /**
     * Row action on a list of behavior notes (the member's audit trail).
     */
    protected function retractBehaviorNoteAction(): Action
    {
        return Action::make('retractBehaviorNote')
            ->label('Retract')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (AttendanceBehaviorNote $record): bool => Gate::allows('retract-behavior-note', $record))
            ->modalHeading(__('Retract behavior note'))
            ->modalDescription(__('The note stops showing on Active Patrons. It stays on this audit trail, marked retracted, with your reason.'))
            ->schema([
                $this->retractionReasonField(),
            ])
            ->action(function (AttendanceBehaviorNote $record, array $data): void {
                abort_unless(Gate::allows('retract-behavior-note', $record), 403);

                app(BehaviorNoteRetractionService::class)->retract($record, auth()->user(), $data['reason']);

                Notification::make()->title(__('Behavior note retracted'))->success()->send();
            });
    }

    /**
     * Row action on a visit (Active Patrons): pick which of its notes to
     * retract, from the ones this user may retract.
     */
    protected function retractBehaviorNoteFromVisitAction(): Action
    {
        return Action::make('retractBehaviorNoteFromVisit')
            ->label('Retract behavior note')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Attendance $record): bool => $this->retractableNotes($record) !== [])
            ->schema(fn (Attendance $record): array => [
                Select::make('note_id')
                    ->label('Note')
                    ->options($this->retractableNotes($record))
                    ->required(),
                $this->retractionReasonField(),
            ])
            ->action(function (Attendance $record, array $data): void {
                $note = $record->behaviorNotes()->whereKey($data['note_id'])->firstOrFail();
                abort_unless(Gate::allows('retract-behavior-note', $note), 403);

                app(BehaviorNoteRetractionService::class)->retract($note, auth()->user(), $data['reason']);

                Notification::make()->title(__('Behavior note retracted'))->success()->send();
            });
    }

    /**
     * "Reason — who", blank for a note still standing.
     */
    protected function retractionColumn(): TextColumn
    {
        return TextColumn::make('retraction.reason')
            ->label('Retracted')
            ->wrap()
            ->placeholder('—')
            ->formatStateUsing(fn (string $state, AttendanceBehaviorNote $record): string => "{$state} — {$record->retraction->retractedBy->name}");
    }

    protected function retractionReasonField(): TextInput
    {
        return TextInput::make('reason')
            ->label('Reason')
            ->required()
            ->maxLength(255);
    }

    /**
     * @return array<int, string> note id => a short preview of its text
     */
    protected function retractableNotes(Attendance $record): array
    {
        return $record->activeBehaviorNotes
            ->filter(fn (AttendanceBehaviorNote $note): bool => Gate::allows('retract-behavior-note', $note))
            ->sortByDesc('created_at')
            ->mapWithKeys(fn (AttendanceBehaviorNote $note): array => [$note->id => Str::limit($note->note, 80)])
            ->all();
    }
}
