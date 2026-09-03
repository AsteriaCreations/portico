<?php

namespace App\Filament\Admin\Resources\PaymentMethods\Schemas;

use App\Models\PaymentMethod;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('label')
                    ->required()
                    ->maxLength(60),
                TextInput::make('code')
                    ->required()
                    ->maxLength(30)
                    ->unique(ignoreRecord: true)
                    // code is the literal value stored on historical
                    // attendance/subscriptions rows -- renaming it after
                    // creation would silently orphan those snapshots, so
                    // it's set once and never again, same disabled()/
                    // dehydrated(false) technique as MemberForm's
                    // member_number and CategoryForm's protected name.
                    ->disabled(fn (?PaymentMethod $record): bool => (bool) $record)
                    ->dehydrated(fn (?PaymentMethod $record): bool => ! $record)
                    ->helperText(fn (?PaymentMethod $record): ?string => $record
                        ? 'The stored value on historical records — cannot be changed once created.'
                        : 'The value stored on attendance/subscription rows, e.g. "cash".'),
                Toggle::make('requires_register_shift')
                    ->label('Requires the register to be open')
                    ->helperText('Only selectable while a shift is open, and counted toward box reconciliation — e.g. Cash.')
                    ->required()
                    ->default(false),
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
