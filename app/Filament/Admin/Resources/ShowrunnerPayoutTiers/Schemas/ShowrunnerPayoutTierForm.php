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
                    ->minValue(0),
                TextInput::make('max_headcount')
                    ->label('Maximum headcount')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Leave blank for the top tier — informational only. The tier with the highest minimum headcount at or below the actual headcount always wins, so its rate keeps applying with no upper bound regardless of what\'s set here.'),
                Select::make('payout_type')
                    ->required()
                    ->live()
                    ->options(PayoutType::class),
                TextInput::make('payout_value')
                    ->label(fn (Get $get): string => $get('payout_type') === PayoutType::Voucher->value ? 'Voucher amount' : 'Percentage of the door')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix(fn (Get $get): ?string => $get('payout_type') === PayoutType::Voucher->value ? '$' : null)
                    ->suffix(fn (Get $get): ?string => $get('payout_type') === PayoutType::Percentage->value ? '%' : null),
            ]);
    }
}
