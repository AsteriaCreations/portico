<?php

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Oversight, not the note record itself -- "who is writing behavior notes
 * and what are they writing," scoped to one staff member, reachable only
 * through UserResource (Admin+). See Member's own BehaviorNotesRelationManager
 * for "what notes exist on this member" (Manager+). Same append-only shape:
 * no create/update/delete path here either, enforced by
 * AttendanceBehaviorNotePolicy.
 */
class BehaviorNotesWrittenRelationManager extends RelationManager
{
    protected static string $relationship = 'writtenBehaviorNotes';

    protected static ?string $title = 'Behavior notes written';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('note')->wrap(),
                TextColumn::make('attendance.member.username')->label('Member'),
                TextColumn::make('attendance.event.name')->label('Event'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
