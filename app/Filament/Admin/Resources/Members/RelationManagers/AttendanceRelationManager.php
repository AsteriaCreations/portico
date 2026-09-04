<?php

namespace App\Filament\Admin\Resources\Members\RelationManagers;

use App\Enums\EntryCoverageSource;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\Event;
use App\Models\PaymentMethod;
use App\Services\PricingService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class AttendanceRelationManager extends RelationManager
{
    protected static string $relationship = 'attendance';

    /**
     * The edit form. Fee/coverage columns are price snapshots — shown for
     * reference but locked, since they should only ever be set by the
     * pricing service at check-in, not hand-edited afterward.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label('Event')
                    ->relationship('event', 'name')
                    ->disabled(),
                DateTimePicker::make('checked_in_at')
                    ->required(),
                TextInput::make('entry_fee')->numeric()->prefix('$')->disabled(),
                TextInput::make('entry_coverage')->numeric()->prefix('$')->disabled(),
                Select::make('entry_covered_by')->options(EntryCoverageSource::class)->disabled(),
                TextInput::make('amount_paid')
                    ->label('Amount paid')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                Select::make('payment_method')
                    ->options(PaymentMethod::options()),
                TextInput::make('on_behalf_note')
                    ->maxLength(120),
                Textarea::make('notes')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('checked_in_at', 'desc')
            ->columns([
                TextColumn::make('event.event_date')
                    ->label('Event date')
                    ->date()
                    ->sortable(),
                TextColumn::make('event.name')
                    ->label('Event')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entry_fee')->money()->sortable(),
                TextColumn::make('entry_coverage')->money()->sortable(),
                TextColumn::make('entry_covered_by')->badge(),
                TextColumn::make('amount_paid')->money()->sortable(),
                TextColumn::make('payment_method')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('checkedInBy.name')
                    ->label('Checked in by')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Check in')
                    ->schema([
                        Select::make('event_id')
                            ->label('Event')
                            ->relationship('event', 'name')
                            ->getOptionLabelFromRecordUsing(
                                fn (Event $record) => "{$record->event_date->toFormattedDateString()} — {$record->name}"
                            )
                            ->searchable()
                            ->preload()
                            ->unique(
                                table: 'attendance',
                                column: 'event_id',
                                modifyRuleUsing: fn (Unique $rule) => $rule->where('member_id', $this->getOwnerRecord()->id),
                            )
                            ->required(),
                        DateTimePicker::make('checked_in_at')
                            ->default(now())
                            ->required(),
                        TextInput::make('payment_method')
                            ->maxLength(30),
                        TextInput::make('on_behalf_note')
                            ->maxLength(120),
                        Textarea::make('notes')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $event = Event::findOrFail($data['event_id']);
                        $breakdown = app(PricingService::class)->price($this->getOwnerRecord(), $event);

                        return [
                            ...$data,
                            'checked_in_by' => auth()->id(),
                            ...$breakdown->toAttendanceAttributes(),
                        ];
                    })
                    // toAttendanceAttributes() above only covers entry --
                    // each subscribable add-on's line (Pool, at launch) is
                    // its own attendance_add_ons row, same as
                    // CheckIn::checkInAction()'s own transaction.
                    ->after(function (Attendance $record): void {
                        $breakdown = app(PricingService::class)->price($this->getOwnerRecord(), $record->event);

                        foreach ($breakdown->addOnAttendanceRows() as $row) {
                            AttendanceAddOn::create(['attendance_id' => $record->id, ...$row]);
                        }
                    }),
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
