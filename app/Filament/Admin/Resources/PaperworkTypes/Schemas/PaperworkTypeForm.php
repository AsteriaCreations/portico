<?php

namespace App\Filament\Admin\Resources\PaperworkTypes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PaperworkTypeForm
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
                Toggle::make('required')
                    ->helperText('Whether the club expects every member to have this on file.')
                    ->required()
                    ->default(true),
                TextInput::make('renewal_months')
                    ->label('Renews every (months)')
                    ->numeric()
                    ->minValue(1)
                    ->default(null)
                    ->helperText('Leave blank for a one-time form that never expires. 12 = renews yearly (e.g. the Pool Waiver).'),
                Select::make('gates_add_on_id')
                    ->label('Gates add-on')
                    ->relationship('addOn', 'name')
                    ->default(null)
                    ->helperText('If set, a member without valid paperwork of this type can\'t use that add-on — it\'s dropped from their pricing and a day-pass purchase is blocked. Pool, for the Pool Waiver.'),
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
