<?php

namespace App\Filament\Admin\Resources\PaperworkTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaperworkTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                IconColumn::make('required')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? 'Expected of every member' : 'Optional'),
                TextColumn::make('renewal_months')
                    ->label('Renews every')
                    ->formatStateUsing(fn (?int $state): string => $state ? "{$state} months" : 'Never')
                    ->placeholder('Never'),
                TextColumn::make('addOn.name')
                    ->label('Gates add-on')
                    ->placeholder('—'),
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
