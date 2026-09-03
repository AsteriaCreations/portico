<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only audit trail — rows are written only by MemberObserver
 * (see MemberUsernameChangePolicy, which forbids create/update/delete here).
 */
class MemberUsernameChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'usernameChanges';

    protected static ?string $title = 'Username change log';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('old_username'),
                TextColumn::make('new_username'),
                TextColumn::make('changedBy.name')->label('Changed by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
