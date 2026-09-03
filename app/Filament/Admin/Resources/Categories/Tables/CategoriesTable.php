<?php

namespace App\Filament\Admin\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                IconColumn::make('is_comped')
                    ->label('Comped')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? 'Comped' : 'Not comped'),
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
                    // A raw bulk query wouldn't consult CategoryPolicy::delete()
                    // per row at all — authorizeIndividualRecords() forces each
                    // selected row through the policy so Prospective/Guest/
                    // Irregular are filtered out of the batch, not just the
                    // single-row EditAction/DeleteAction path.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
