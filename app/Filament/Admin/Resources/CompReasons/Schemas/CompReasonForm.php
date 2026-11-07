<?php

namespace App\Filament\Admin\Resources\CompReasons\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

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
                    ->helperText(__('Leave blank for no automatic voucher. If set, a member comped for this reason gets a voucher of this amount once the event ends.')),
                Toggle::make('voucher_only_if_already_covered')
                    ->label(__('Voucher only if entry was already free'))
                    ->default(false)
                    ->helperText(__('Give the voucher only to members whose entry was already covered (subscription, comped category, host). Members who would have paid get free entry instead, with no voucher.')),
                Toggle::make('door_allowed')
                    ->label(__('Door staff can comp with this reason'))
                    ->default(false)
                    // Disabled fields aren't dehydrated, so a Manager's save
                    // never touches it; CompReasonObserver re-checks anyway.
                    ->disabled(fn (): bool => Gate::denies('manage-door-comp-reasons'))
                    ->helperText(__('Lets Door-role staff waive entry at the desk for this reason without a Manager, e.g. "Brought donation items". Only Admins can change this.')),
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
