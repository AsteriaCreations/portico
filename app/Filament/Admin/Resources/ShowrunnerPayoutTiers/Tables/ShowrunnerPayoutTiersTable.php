<?php

namespace App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Tables;

use App\Enums\PayoutType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShowrunnerPayoutTiersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('min_headcount')
            ->columns([
                TextColumn::make('min_headcount')
                    ->label('Min headcount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('max_headcount')
                    ->label('Max headcount')
                    ->numeric()
                    ->placeholder('No cap'),
                TextColumn::make('payout_type')
                    ->badge(),
                TextColumn::make('payout_value')
                    ->label('Payout')
                    ->formatStateUsing(fn (string $state, $record): string => $record->payout_type === PayoutType::Voucher
                        ? '$'.number_format((float) $state, 2)
                        : number_format((float) $state, 2).'%'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
