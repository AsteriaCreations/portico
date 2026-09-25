<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Models\Event;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Grants a specific banned member admission to one specific event without
 * lifting the ban overall (e.g. a re-introduction newbie night). Surfaces at
 * the door as a WARN, not a silent OK — see AdmissionPolicy::decide().
 * No edit/delete path: see BanExceptionPolicy.
 */
class BanExceptionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'banExceptions';

    protected static ?string $title = 'Ban exceptions';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label('Event')
                    // A new exception can't target an archived event; an existing
                    // one still shows its event when edited.
                    ->relationship('event', 'name', modifyQueryUsing: fn (Builder $query, string $operation): Builder => $operation === 'create' ? $query->whereNull('archived_at') : $query)
                    ->getOptionLabelFromRecordUsing(fn (Event $record): string => $record->label())
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
