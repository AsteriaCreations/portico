<?php

namespace App\Filament\Admin\Resources\CleaningTasks\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only completion log — rows are written only by CleaningChecklist's
 * complete action (see CleaningTaskCompletionPolicy, which forbids
 * create/update/delete here).
 */
class CompletionsRelationManager extends RelationManager
{
    protected static string $relationship = 'completions';

    protected static ?string $title = 'Completion log';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('for_week_start', 'desc')
            ->columns([
                TextColumn::make('for_week_start')->label('Week of')->date()->sortable(),
                TextColumn::make('completedBy.name')->label('Completed by'),
                TextColumn::make('notes'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
