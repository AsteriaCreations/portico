<?php

namespace App\Filament\Admin\Resources\Plans\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('add_on_id')
                    ->label('Target')
                    ->relationship('addOn', 'name')
                    ->live()
                    ->required(),
                TextInput::make('duration_months')
                    ->label('Duration (months)')
                    ->helperText('1 = the ordinary monthly rate. A number greater than 1 offers a bulk-discount bundle of that many months at check-in and on the Subscriptions admin bulk-purchase action.')
                    ->live()
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->default(1),
                TextInput::make('price')
                    ->helperText('The total price for this plan\'s duration — e.g. $175 for a 3-month bundle, not a per-month figure.')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('credit')
                    ->helperText('Per-visit credit applied to the target\'s fee. Only meaningful on a 1-month plan — a bundle purchase still draws the ordinary monthly credit each visit. Leave blank for full coverage instead of a fixed credit.')
                    ->numeric()
                    ->prefix('$')
                    ->hidden(fn (Get $get): bool => (int) $get('duration_months') !== 1)
                    ->default(null),
                DatePicker::make('effective_from')
                    ->required(),
                DatePicker::make('effective_to')
                    ->helperText('Leave blank if this is the current plan.'),
            ]);
    }
}
