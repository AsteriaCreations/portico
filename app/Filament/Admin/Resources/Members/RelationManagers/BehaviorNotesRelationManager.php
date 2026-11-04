<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use App\Filament\Concerns\RetractsBehaviorNotes;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Models\AttendanceBehaviorNote;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Audit trail. Rows are written only by ActivePatrons::addBehaviorNoteAction()
 * and are never edited or deleted (AttendanceBehaviorNotePolicy); the one
 * action here is a retraction, which adds its own append-only row
 * (BehaviorNoteRetractionService). A retracted note stays listed, marked so.
 * Reachable indefinitely, unlike Attendance::visit_note, which nothing outside
 * ActivePatrons ever shows.
 */
class BehaviorNotesRelationManager extends RelationManager
{
    use RetractsBehaviorNotes;
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'behaviorNotes';

    protected static ?string $title = 'Behavior notes';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['retraction.retractedBy']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('note')
                    ->wrap()
                    ->color(fn (AttendanceBehaviorNote $record): ?string => $record->retraction ? 'gray' : null),
                TextColumn::make('attendance.event.name')->label('Event'),
                TextColumn::make('createdBy.name')->label('Written by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
                $this->retractionColumn(),
            ])
            ->headerActions([])
            ->recordActions([
                $this->retractBehaviorNoteAction(),
            ])
            ->toolbarActions([]);
    }
}
