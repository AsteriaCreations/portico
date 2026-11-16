<?php

namespace App\Filament\Admin\Resources\EventTimelineCategories\Schemas;

use App\Enums\Role;
use App\Models\EventTimelineCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EventTimelineCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->helperText(__('Who these items are for, or what kind they are: Public, Event staff, Bar, Setup…'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(60),
                Select::make('color')
                    ->label(__('Colour'))
                    ->options(EventTimelineCategory::colorOptions())
                    ->default('#4b5563')
                    ->required(),
                Toggle::make('is_public')
                    ->label(__('Public'))
                    ->helperText(__('For attendees: on the printed timeline and the display screen, and seen by every signed-in user.'))
                    ->live()
                    ->default(false),
                Select::make('min_role')
                    ->label(__('Seen by staff from'))
                    ->helperText(__('Staff at or above this role see items in this category. Leave empty for the event\'s showrunner and Admin+ only.'))
                    ->options(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->displayLabel()])->all())
                    ->placeholder(__('Showrunner and Admin+ only'))
                    ->visible(fn (Get $get): bool => ! $get('is_public'))
                    ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => $get('is_public') ? null : $state),
                TextInput::make('sort_order')
                    ->label(__('Sort order'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->default(0),
            ]);
    }
}
