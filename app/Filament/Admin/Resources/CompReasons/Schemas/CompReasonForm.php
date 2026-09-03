<?php

namespace App\Filament\Admin\Resources\CompReasons\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CompReasonForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(60),
                TextInput::make('description')
                    ->maxLength(255)
                    ->default(null),
                TextInput::make('grants_voucher_amount')
                    ->label('Grants a voucher of')
                    ->numeric()
                    ->prefix('$')
                    ->default(null)
                    ->helperText('Leave blank for no automatic voucher. If set, a member comped for this reason gets a voucher of this amount once the event ends.'),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('active')
                    ->required()
                    ->default(true),
            ]);
    }
}
