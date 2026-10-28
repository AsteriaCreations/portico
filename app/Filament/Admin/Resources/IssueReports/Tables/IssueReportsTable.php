<?php

namespace App\Filament\Admin\Resources\IssueReports\Tables;

use App\Models\IssueReport;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
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
                IconColumn::make('posted_at')
                    ->label('Posted')
                    ->boolean()
                    ->state(fn (IssueReport $record): bool => $record->posted_at !== null)
                    ->tooltip(fn (IssueReport $record): string => $record->posted_at !== null
                        ? __('Posted to GitHub')
                        : __('Not posted')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
