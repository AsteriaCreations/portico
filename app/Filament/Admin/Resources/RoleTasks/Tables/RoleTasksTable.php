<?php

namespace App\Filament\Admin\Resources\RoleTasks\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class RoleTasksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['shiftRole', 'area']))
            ->defaultGroup(Group::make('shiftRole.name')->label(__('Shift role'))->titlePrefixedWithLabel(false))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Task'))
                    ->searchable()
                    ->description(fn ($record): ?string => $record->description),
                TextColumn::make('area.name')
                    ->label(__('Area'))
                    ->placeholder(__('No area')),
                TextColumn::make('sort_order')
                    ->label(__('Sort order'))
                    ->numeric()
                    ->sortable(),
                IconColumn::make('active')
                    ->label(__('Active'))
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state ? __('Active') : __('Inactive')),
            ])
            ->filters([
                SelectFilter::make('shift_role_id')
                    ->label(__('Shift role'))
                    ->relationship('shiftRole', 'name'),
                SelectFilter::make('task_area_id')
                    ->label(__('Area'))
                    ->relationship('area', 'name'),
                TernaryFilter::make('active')
                    ->label(__('Active')),
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
