<?php

namespace App\Filament\Admin\Resources\EventTimelineCategories\Tables;

use App\Models\EventTimelineCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventTimelineCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                ColorColumn::make('color')
                    ->label(__('Colour'))
                    ->tooltip(fn (string $state): string => EventTimelineCategory::colorOptions()[$state] ?? $state),
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),
                TextColumn::make('audience')
                    ->label(__('Seen by'))
                    ->state(fn (EventTimelineCategory $record): string => match (true) {
                        $record->is_public => __('Everyone (public)'),
                        $record->min_role !== null => __(':role and up', ['role' => $record->min_role->displayLabel()]),
                        default => __('Showrunner and Admin+ only'),
                    }),
                TextColumn::make('items_count')
                    ->label(__('Items'))
                    ->counts('items')
                    ->numeric(),
                TextColumn::make('sort_order')
                    ->label(__('Sort order'))
                    ->numeric()
                    ->sortable(),
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
