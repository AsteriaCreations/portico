<?php

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only ledger of one-time pool day passes sold for this event — rows
 * are written only through CheckIn::purchasePoolDayPassAction() (see
 * PoolDayPassPolicy, which forbids update/delete here).
 */
class PoolDayPassesRelationManager extends RelationManager
{
    protected static string $relationship = 'poolDayPasses';

    protected static ?string $title = 'Pool day passes';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member.username')
                    ->label('Member'),
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
