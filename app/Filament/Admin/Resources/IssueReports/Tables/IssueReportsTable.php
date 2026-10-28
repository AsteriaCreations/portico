<?php

namespace App\Filament\Admin\Resources\IssueReports\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IssueReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#'),
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('snapshot.event.name')
                    ->label('Event')
                    ->placeholder(__('—')),
                TextColumn::make('note')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('reportedBy.name')
                    ->label('Reported by'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
