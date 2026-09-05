<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use App\Models\PaperworkType;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The member's signed paperwork / waivers, one row per signing. Append-only
 * (no edit/delete — see MemberPaperworkPolicy); a renewed waiver is a new
 * row. Member::hasValidPaperwork() reads the latest per type.
 */
class MemberPaperworkRelationManager extends RelationManager
{
    protected static string $relationship = 'paperwork';

    protected static ?string $title = 'Paperwork & waivers';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('paperwork_type_id')
                    ->label('Type')
                    ->options(fn () => PaperworkType::where('active', true)->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->required(),
                DatePicker::make('signed_on')
                    ->required()
                    ->default(now()),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('signed_on', 'desc')
            ->columns([
                TextColumn::make('paperworkType.name')->label('Type')->sortable(),
                TextColumn::make('signed_on')->date()->sortable(),
                TextColumn::make('recordedBy.name')->label('Recorded by')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => [
                        ...$data,
                        'recorded_by' => auth()->id(),
                    ]),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
