<?php

namespace App\Filament\Admin\Resources\ShiftRoles\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShiftRolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->description(fn ($record): ?string => $record->description),
                TextColumn::make('users.name')
                    ->label(__('Who can work it'))
                    ->listWithLineBreaks()
                    ->limitList(5)
                    ->expandableLimitedList()
                    ->placeholder(fn ($record): string => $record->open_to_all ? __('Anyone') : __('No one yet')),
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
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
