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
                    ->tooltip(fn (bool $state): string => $state ? __('Expected of every member') : __('Optional')),
                TextColumn::make('renewal_months')
                    ->label('Renews every')
                    ->formatStateUsing(fn (?int $state): string => $state ? trans_choice(':count month|:count months', $state) : __('Never'))
                    ->placeholder(__('Never')),
                TextColumn::make('addOn.name')
                    ->label('Gates add-on')
                    ->placeholder(__('—')),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
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
