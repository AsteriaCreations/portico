<?php

namespace App\Filament\Admin\Resources\AddOns\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AddOnsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('price')
                    ->money(),
                TextColumn::make('max_per_night')
                    ->label('Max/night')
                    ->numeric()
                    ->placeholder('Unlimited'),
                IconColumn::make('is_overnight')
                    ->label('Overnight')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? 'Overnight stay' : 'Not an overnight stay'),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? 'Active' : 'Inactive'),
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
