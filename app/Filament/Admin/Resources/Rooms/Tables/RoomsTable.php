<?php

namespace App\Filament\Admin\Resources\Rooms\Tables;

use App\Models\Room;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RoomsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),
                TextColumn::make('hourly_rate')
                    ->label(__('Per hour'))
                    ->money(),
                TextColumn::make('included_people')
                    ->label(__('People included'))
                    ->numeric(),
                TextColumn::make('overage_fee')
                    ->label(__('Extra person'))
                    ->money()
                    ->description(fn (Room $record): string => $record->overage_per_hour ? __('per hour') : __('once')),
                TextColumn::make('min_hours')
                    ->label(__('Hours'))
                    ->formatStateUsing(fn (Room $record): string => __(':min–:max', ['min' => $record->min_hours, 'max' => $record->max_hours])),
                TextColumn::make('sort_order')
                    ->label(__('Sort order'))
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
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
