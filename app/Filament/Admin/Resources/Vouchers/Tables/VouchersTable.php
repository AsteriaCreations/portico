<?php

namespace App\Filament\Admin\Resources\Vouchers\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VouchersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member.username')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money()
                    ->color(fn (float $state) => $state >= 0 ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('reason')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('attendance.event.name')
                    ->label('Redeemed at')
                    ->placeholder('— not tied to a check-in')
                    ->toggleable(),
                TextColumn::make('recordedBy.name')
                    ->label('Recorded by'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultPaginationPageOption(25);
    }
}
