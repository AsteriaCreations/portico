<?php

namespace App\Filament\Admin\Resources\RegisterShifts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only itemized ledger — rows are written only through CheckIn's
 * recordMiscPaymentAction() via RegisterShiftService::recordMiscPayment()
 * (see MiscellaneousPaymentPolicy, which forbids update/delete here).
 */
class MiscellaneousPaymentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'miscellaneousPayments';

    protected static ?string $title = 'Other payments';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('payment_method'),
                TextColumn::make('amount')->money()->sortable(),
                TextColumn::make('notation'),
                TextColumn::make('recordedBy.name')->label('Recorded by'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
