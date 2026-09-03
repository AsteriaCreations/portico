<?php

namespace App\Filament\Admin\Resources\EventTypes\RelationManagers;

use App\Enums\EntryCoverageSource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Per-attendee instructor pay for this event type, broken out by how the
 * attendee's entry was covered (the same axis PricingService already
 * produces on Attendance::entry_covered_by) -- piloted on "Yoga" but a
 * general mechanism any event type can use. A bucket with no row here pays
 * $0; see App\Services\InstructorPayoutService.
 */
class InstructorPayRatesRelationManager extends RelationManager
{
    protected static string $relationship = 'instructorPayRates';

    protected static ?string $title = 'Instructor pay rates';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('entry_covered_by')
                    ->required()
                    ->options(EntryCoverageSource::class)
                    ->unique(
                        table: 'instructor_pay_rates',
                        column: 'entry_covered_by',
                        modifyRuleUsing: fn ($rule) => $rule->where('event_type_id', $this->getOwnerRecord()->id),
                        ignoreRecord: true,
                    ),
                TextInput::make('rate')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix('$'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('entry_covered_by')
            ->columns([
                TextColumn::make('entry_covered_by')
                    ->badge(),
                TextColumn::make('rate')
                    ->money(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
