<?php

namespace App\Filament\Admin\Resources\PaymentCorrections\Tables;

use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared by PaymentCorrectionResource and the member page's
 * PaymentCorrectionsRelationManager -- same read-only columns in both.
 */
class PaymentCorrectionsTable
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
                TextColumn::make('attendance.member.username')
                    ->label('Member')
                    ->searchable(),
                TextColumn::make('attendance.event.name')
                    ->label('Event'),
                TextColumn::make('old_amount_paid')
                    ->label('Visit paid before')
                    ->money(),
                TextColumn::make('new_amount_paid')
                    ->label('Visit paid after')
                    ->money(),
                TextColumn::make('subscription_amount')
                    ->label('Subscription')
                    ->money(),
                TextColumn::make('net_amount')
                    ->label('Collected (+) / refunded (−)')
                    ->money()
                    ->color(fn (string $state): string => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('payment_method')
                    ->label('Method')
                    ->placeholder(__('—')),
                TextColumn::make('registerShift.register.name')
                    ->label('Register')
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('reason')
                    ->wrap(),
                TextColumn::make('correctedBy.name')
                    ->label('Corrected by'),
            ])
            ->filters([
                Filter::make('corrected')
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
                            $indicators[] = __('From :date', ['date' => Carbon::parse($data['from'])->isoFormat('ll')]);
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = __('Until :date', ['date' => Carbon::parse($data['until'])->isoFormat('ll')]);
                        }

                        return $indicators;
                    }),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
