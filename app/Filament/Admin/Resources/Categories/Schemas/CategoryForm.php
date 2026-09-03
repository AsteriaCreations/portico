<?php

namespace App\Filament\Admin\Resources\Categories\Schemas;

use App\Models\Category;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(40)
                    // Genuinely server-side, not just greyed out: a disabled
                    // field is excluded from getState(), the same technique
                    // MemberForm uses for member_number — see
                    // Category::PROTECTED_NAMES.
                    ->disabled(fn (?Category $record): bool => (bool) $record && in_array($record->name, Category::PROTECTED_NAMES, true))
                    ->dehydrated(fn (?Category $record): bool => ! ($record && in_array($record->name, Category::PROTECTED_NAMES, true)))
                    ->helperText(fn (?Category $record): ?string => ($record && in_array($record->name, Category::PROTECTED_NAMES, true))
                        ? 'This category name is relied on by check-in logic and cannot be renamed.'
                        : null),
                TextInput::make('description')
                    ->maxLength(255)
                    ->default(null),
                Toggle::make('is_comped')
                    ->label('Comped')
                    ->helperText('Members in this category are fully comped on entry and pool fees.')
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
