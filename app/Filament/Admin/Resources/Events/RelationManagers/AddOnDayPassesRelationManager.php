<?php

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only ledger of one-time add-on day passes sold for this event (Pool,
 * the only day-passable add-on today) — rows are written only through
 * CheckIn::purchaseAddOnDayPassAction() (see AddOnDayPassPolicy, which
 * forbids update/delete here).
 */
class AddOnDayPassesRelationManager extends RelationManager
{
    protected static string $relationship = 'addOnDayPasses';

    protected static ?string $title = 'Add-on day passes';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member.username')
                    ->label('Member'),
                TextColumn::make('addOn.name')
                    ->label('Add-on'),
                TextColumn::make('amount_paid')
                    ->money()
                    ->sortable(),
                TextColumn::make('payment_method'),
                TextColumn::make('recordedBy.name')
                    ->label('Recorded by'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
