<?php

namespace App\Filament\Admin\Resources\RegisterShifts\Tables;

use App\Models\RegisterShift;
use App\Services\RegisterShiftService;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RegisterShiftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('register.name')
                    ->label('Register')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('openedBy.name')
                    ->label('Opened by'),
                TextColumn::make('created_at')
                    ->label('Opened at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('opening_count')
                    ->label('Opening count')
                    ->money(),
                TextColumn::make('closedBy.name')
                    ->label('Closed by')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('closed_at')
                    ->label('Closed at')
                    ->dateTime()
                    ->placeholder('— still open')
                    ->sortable(),
                TextColumn::make('closing_count')
                    ->label('Closing count')
                    ->money()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('drops_total')
                    ->label('Drops')
                    ->state(fn (RegisterShift $record) => app(RegisterShiftService::class)->totalDrops($record))
                    ->money(),
                TextColumn::make('event_revenue')
                    ->label('Event')
                    ->state(fn (RegisterShift $record) => app(RegisterShiftService::class)->revenueBreakdown($record)['event'])
                    ->money()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('subscription_revenue')
                    ->label('Subscription')
                    ->state(fn (RegisterShift $record) => app(RegisterShiftService::class)->revenueBreakdown($record)['subscription'])
                    ->money()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('other_revenue')
                    ->label('Other')
                    ->state(fn (RegisterShift $record) => app(RegisterShiftService::class)->revenueBreakdown($record)['other'])
                    ->money()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variance')
                    ->state(fn (RegisterShift $record) => app(RegisterShiftService::class)->variance($record))
                    ->money()
                    ->placeholder('— open')
                    ->color(fn (?float $state) => match (true) {
                        $state === null => 'gray',
                        $state == 0.0 => 'success',
                        $state > 0 => 'warning',
                        default => 'danger',
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultPaginationPageOption(25);
    }
}
