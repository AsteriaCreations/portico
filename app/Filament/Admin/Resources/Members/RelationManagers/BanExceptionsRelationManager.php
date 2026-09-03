<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Grants a specific banned member admission to one specific event without
 * lifting the ban overall (e.g. a re-introduction newbie night). Surfaces at
 * the door as a WARN, not a silent OK — see AdmissionPolicy::decide().
 * No edit/delete path: see BanExceptionPolicy.
 */
class BanExceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'banExceptions';

    protected static ?string $title = 'Ban exceptions';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label('Event')
                    ->relationship('event', 'name')
                    ->getOptionLabelFromRecordUsing(
                        fn ($record) => "{$record->event_date->toFormattedDateString()} — {$record->name}"
                    )
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('reason')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('event.event_date')->label('Event date')->date()->sortable(),
                TextColumn::make('event.name')->label('Event'),
                TextColumn::make('reason'),
                TextColumn::make('grantedBy.name')->label('Granted by'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...$data,
                        'granted_by' => auth()->id(),
                    ]),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
