<?php

namespace App\Filament\Admin\Resources\Vouchers\Schemas;

use App\Models\Member;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class VoucherForm
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
                    ->required(),
                TextInput::make('amount')
                    ->label('Amount')
                    ->helperText('Positive to issue credit, negative to correct a wrongly-issued voucher (e.g. -25 to void a $25 grant).')
                    ->required()
                    ->numeric()
                    ->prefix('$')
                    ->rule('decimal:0,2')
                    ->notIn([0])
                    ->validationMessages(['not_in' => 'Amount cannot be zero.']),
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Required on every row — this is the only record of why a balance changed.')
                    ->required()
                    ->maxLength(255),
                Hidden::make('recorded_by')
                    ->default(fn () => Auth::id()),
            ]);
    }
}
