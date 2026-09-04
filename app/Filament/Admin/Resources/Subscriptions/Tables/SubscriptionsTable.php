<?php

namespace App\Filament\Admin\Resources\Subscriptions\Tables;

use App\Models\AddOn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('covered_month', 'desc')
            ->columns([
                TextColumn::make('member.username')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('addOn.name')
                    ->label('Plan')
                    ->badge(),
                TextColumn::make('covered_month')
                    ->date('F Y')
                    ->sortable(),
                TextColumn::make('amount_paid')
                    ->money()
                    ->sortable(),
                TextColumn::make('paid_on')
                    ->date()
                    ->sortable(),
                TextColumn::make('recordedBy.name')
                    ->label('Recorded by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Every add-on, not just currently-subscribable ones -- a
                // legitimate old subscription for an add-on that's since
                // had subscribable turned off (or Pool while pool_enabled
                // is off) must stay filterable, same reasoning
                // AddOn::subscribable() itself never applies to browsing.
                SelectFilter::make('add_on_id')
                    ->label('Plan')
                    ->options(fn () => AddOn::orderBy('sort_order')->pluck('name', 'id')->all()),
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
