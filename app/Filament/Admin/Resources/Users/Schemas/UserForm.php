<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\Role;
use App\Models\Member;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('Leave blank to keep the current password.'),
                Select::make('role')
                    // Not ->options(Role::class) -- that resolves labels via
                    // Role::getLabel() only, which never consults a club's
                    // own per-role alias. See Role::displayLabel().
                    ->options(fn () => collect(Role::cases())->mapWithKeys(fn (Role $role) => [$role->value => $role->displayLabel()])->all())
                    ->default(Role::Door)
                    ->required(),
                Select::make('member_id')
                    ->label('Linked member')
                    ->relationship('member', 'username')
                    ->getOptionLabelFromRecordUsing(
                        fn (Member $record) => "{$record->last_name}, {$record->first_name} ({$record->username})"
                    )
                    ->searchable(Member::searchableColumns())
                    ->preload()
                    ->unique(ignoreRecord: true)
                    ->helperText("Optional — connects this login to the member's own record."),
                Toggle::make('active')
                    ->default(true)
                    ->required(),
            ]);
    }
}
