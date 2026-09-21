<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only audit trail — rows are written only by MemberObserver
 * (see MemberStatusChangePolicy, which forbids create/update/delete here).
 */
class MemberStatusChangesRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'statusChanges';

    protected static ?string $title = 'Status change log';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('status')->badge(),
                IconColumn::make('value')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Turned on') : __('Turned off')),
                TextColumn::make('reason'),
                TextColumn::make('changedBy.name')->label('Changed by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
