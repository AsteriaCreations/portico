<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Models\PaymentMethod;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only -- rows are written only by PaymentMethodCorrectionService (see
 * PaymentMethodCorrectionPolicy, which forbids create/update/delete here).
 */
class PaymentMethodCorrectionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'paymentMethodCorrections';

    protected static ?string $title = 'Payment method corrections';

    public function table(Table $table): Table
    {
        $methods = PaymentMethod::query()->pluck('label', 'code')->all();

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('attendance.event.name')
                    ->label('Event'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money(),
                TextColumn::make('old_payment_method')
                    ->label('Was recorded as')
                    ->formatStateUsing(fn (?string $state): string => $methods[$state] ?? $state ?? '')
                    ->placeholder(__('—')),
                TextColumn::make('new_payment_method')
                    ->label('Changed to')
                    ->formatStateUsing(fn (string $state): string => $methods[$state] ?? $state),
                TextColumn::make('reason')
                    ->wrap(),
                TextColumn::make('correctedBy.name')
                    ->label('Corrected by'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
