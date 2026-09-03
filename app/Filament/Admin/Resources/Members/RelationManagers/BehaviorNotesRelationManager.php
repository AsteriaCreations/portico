<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only audit trail — rows are written only by
 * ActivePatrons::addBehaviorNoteAction() (see AttendanceBehaviorNotePolicy,
 * which forbids create/update/delete here). Reachable indefinitely, unlike
 * Attendance::visit_note, which nothing outside ActivePatrons ever shows.
 */
class BehaviorNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'behaviorNotes';

    protected static ?string $title = 'Behavior notes';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('note')->wrap(),
                TextColumn::make('attendance.event.name')->label('Event'),
                TextColumn::make('createdBy.name')->label('Written by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
