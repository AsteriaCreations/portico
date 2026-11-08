<?php

namespace App\Filament\Admin\Resources\PrivateRentals\Tables;

use App\Models\PrivateRental;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PrivateRentalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['room', 'member']))
            ->defaultSort('starts_at')
            ->columns([
                TextColumn::make('starts_at')
                    ->label(__('When'))
                    ->formatStateUsing(fn (PrivateRental $record): string => __(':from–:to', [
                        'from' => $record->starts_at->isoFormat('llll'),
                        'to' => $record->ends_at->isoFormat('LT'),
                    ]))
                    ->sortable(),
                TextColumn::make('room.name')
                    ->label(__('Room')),
                TextColumn::make('member_id')
                    ->label(__('Renter'))
                    ->formatStateUsing(fn (PrivateRental $record): string => $record->member->displayName()),
                TextColumn::make('headcount')
                    ->label(__('People'))
                    ->numeric(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->money(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->state(fn (PrivateRental $record): string => match (true) {
                        $record->cancelled_at !== null => __('Cancelled'),
                        $record->ends_at->isPast() => __('Over'),
                        default => __('Booked'),
                    })
                    ->badge()
                    ->color(fn (PrivateRental $record): string => match (true) {
                        $record->cancelled_at !== null => 'gray',
                        $record->ends_at->isPast() => 'gray',
                        default => 'success',
                    }),
            ])
            ->filters([
                Filter::make('upcoming')
                    ->label(__('Upcoming only'))
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->whereNull('cancelled_at')->where('ends_at', '>', now())),
                SelectFilter::make('room_id')
                    ->label(__('Room'))
                    ->relationship('room', 'name'),
            ]);
    }
}
