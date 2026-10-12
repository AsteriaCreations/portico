<?php

namespace App\Filament\Admin\Resources\ShowrunnerPayoutTiers\Schemas;

use App\Enums\PayoutType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ShowrunnerPayoutTierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('min_headcount')
                    ->label('Minimum headcount')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->helperText(__('The first attendee number this tier covers, e.g. 36.')),
                TextInput::make('max_headcount')
                    ->label('Maximum headcount')
                    ->numeric()
                    ->minValue(0)
                    ->gte('min_headcount')
                    ->helperText(__('The last attendee number it covers, e.g. 100. Leave blank for no upper limit.')),
                Select::make('payout_type')
                    ->required()
                    ->live()
                    ->options(PayoutType::class)
                    ->helperText(__('Percentage tiers stack like tax brackets: each pays its rate on the entry fee for the attendees numbered inside it. A voucher is a flat amount, paid only when the whole headcount falls inside its range.')),
                TextInput::make('payout_value')
                    ->label(fn (Get $get): string => $get('payout_type') === PayoutType::Voucher->value ? 'Voucher amount' : 'Percentage of the entry fee')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix(fn (Get $get): ?string => $get('payout_type') === PayoutType::Voucher->value ? '$' : null)
                    ->suffix(fn (Get $get): ?string => $get('payout_type') === PayoutType::Percentage->value ? '%' : null),
            ]);
    }
}
