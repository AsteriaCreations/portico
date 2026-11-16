<?php

namespace App\Filament\Admin\Resources\Subscriptions\Tables;

use App\Models\AddOn;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                // Any day in the month will do: covered_month is its first
                // day. Analytics links its active-subscriber count here.
                Filter::make('covered_month')
                    ->schema([
                        DatePicker::make('month')->label('Covers the month of'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['month'] ?? null,
                        fn (Builder $q, string $date) => $q->whereDate('covered_month', Carbon::parse($date)->startOfMonth()->toDateString()),
                    ))
                    ->indicateUsing(fn (array $data): array => ($data['month'] ?? null)
                        ? [__('Covers :month', ['month' => Carbon::parse($data['month'])->isoFormat('MMMM YYYY')])]
                        : []),
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
