<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only history of Owner watchlist decisions -- rows are written only by
 * Member::resolveWatchlistReview() (see WatchlistReviewPolicy, which forbids
 * create/update/delete here).
 */
class WatchlistReviewsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'watchlistReviews';

    protected static ?string $title = 'Watchlist reviews';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('decision')->badge(),
                TextColumn::make('previous_review_on')->label('Review date was')->date()->placeholder('—'),
                TextColumn::make('new_review_on')->label('New review date')->date()->placeholder('—'),
                IconColumn::make('probation_started')
                    ->label('Probation')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Probation started') : __('No probation')),
                TextColumn::make('notes'),
                TextColumn::make('decidedBy.name')->label('Decided by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
