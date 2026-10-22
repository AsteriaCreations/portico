<?php

namespace App\Filament\Admin\Resources\RegisterShifts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only itemized ledger — rows are written only through CheckIn's
 * recordCashExchangeAction() via RegisterShiftService::recordCashExchange()
 * (see CashExchangePolicy, which forbids update/delete here). Shown even
 * with cash_exchanges_enabled off, so turning the flag off never hides
 * what was recorded.
 */
class CashExchangesRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'cashExchanges';

    protected static ?string $title = 'Cash for electronic payments';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('payment_method'),
                TextColumn::make('amount')->label('Cash handed out')->money()->sortable(),
                TextColumn::make('fee')->money(),
                TextColumn::make('notes'),
                TextColumn::make('recordedBy.name')->label('Recorded by'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
