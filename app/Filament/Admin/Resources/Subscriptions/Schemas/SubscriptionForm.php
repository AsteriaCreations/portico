<?php

namespace App\Filament\Admin\Resources\Subscriptions\Schemas;

use App\Models\Member;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'username')
                    ->getOptionLabelFromRecordUsing(fn (Member $record) => Member::pickerLabel($record))
                    ->searchable(Member::searchableColumns())
                    ->preload()
                    ->required()
                    ->rule(
                        fn () => function (string $attribute, $value, $fail) {
                            if ($value && ! Member::find($value)?->isSubscriptionEligible()) {
                                $fail('This member is not yet subscription-eligible.');
                            }
                        },
                        condition: fn (string $operation): bool => $operation === 'create',
                    ),
                Select::make('add_on_id')
                    ->label('Plan')
                    ->relationship('addOn', 'name')
                    ->required(),
                DatePicker::make('covered_month')
                    ->helperText('The calendar month this payment covers — stored as the first of the month.')
                    ->required()
                    ->dehydrateStateUsing(fn (?string $state) => $state ? Carbon::parse($state)->startOfMonth()->toDateString() : null),
                TextInput::make('amount_paid')
                    ->label('Amount paid')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->default(0),
                DatePicker::make('paid_on'),
                Hidden::make('recorded_by')
                    ->default(fn () => Auth::id()),
            ]);
    }
}
