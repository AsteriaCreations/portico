<?php

namespace App\Filament\Admin\Resources\TaskAreas\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TaskAreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->helperText(__('Where the work is done: Bathrooms, Main floor, Bar…'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(60),
                Textarea::make('notes')
                    ->label(__('How this area is handled'))
                    ->helperText(__('Shown with its tasks, for an area cared for differently: "Bleach only, gloves in the cabinet".'))
                    ->maxLength(255)
                    ->rows(2)
                    ->columnSpanFull(),
                TextInput::make('sort_order')
                    ->label(__('Sort order'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->default(0),
                Toggle::make('active')
                    ->label(__('Active'))
                    ->helperText(__('Turn off to stop offering this area for new tasks. Tasks already in it keep it.'))
                    ->required()
                    ->default(true),
            ]);
    }
}
