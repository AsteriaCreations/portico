<?php

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use App\Enums\Capability;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Grants/revokes capabilities orthogonal to the user's role (e.g. Cleaning
 * Crew) -- see App\Enums\Capability. Unlike BanExceptionsRelationManager's
 * append-only shape, revoke is a normal operation here, so a delete action
 * is deliberately included.
 */
class CapabilitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'capabilities';

    protected static ?string $title = 'Capabilities';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('capability')
                    ->options(Capability::class)
                    ->required()
                    ->unique(
                        table: 'user_capabilities',
                        column: 'capability',
                        modifyRuleUsing: fn ($rule) => $rule->where('user_id', $this->getOwnerRecord()->id),
                        ignoreRecord: true,
                    ),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('capability')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('capability')->badge(),
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
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }
}
