<?php

namespace App\Filament\Admin\Resources\PaymentMethods\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentMethodsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('label')
                    ->searchable(),
                TextColumn::make('code')
                    ->searchable(),
                IconColumn::make('requires_register_shift')
                    ->label('Register only')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? 'Requires an open register shift' : 'Selectable with no register shift open'),
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
