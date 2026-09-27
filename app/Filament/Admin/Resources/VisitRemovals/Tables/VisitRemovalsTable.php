<?php

namespace App\Filament\Admin\Resources\VisitRemovals\Tables;

use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitRemovalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('member.username')
                    ->label('Member')
                    ->searchable(),
                TextColumn::make('event.name')
                    ->label('Event'),
                TextColumn::make('amount_paid')
                    ->label('Amount paid')
                    ->money(),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->placeholder(__('—')),
                TextColumn::make('registerShift.register.name')
                    ->label('Register')
                    ->placeholder(__('—'))
                    ->toggleable(),
                IconColumn::make('after_shift_closed')
                    ->label('After close')
                    ->boolean()
                    ->tooltip(fn (bool $state): string => $state
                        ? __('Removed after its register shift closed — that shift\'s totals were left as they were.')
                        : __('Removed while its register shift was open — refunded from that drawer.')),
                TextColumn::make('reason')
                    ->wrap(),
                TextColumn::make('removedBy.name')
                    ->label('Removed by'),
            ])
            ->filters([
                Filter::make('removed')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = __('From :date', ['date' => Carbon::parse($data['from'])->translatedFormat('M j, Y')]);
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = __('Until :date', ['date' => Carbon::parse($data['until'])->translatedFormat('M j, Y')]);
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
