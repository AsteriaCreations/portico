<?php

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Models\CompReason;
use App\Models\Member;
use App\Services\CapacityService;
use App\Services\PricingService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A per-event admin-managed comp list — same underlying mechanism as the
 * Prepay List (an unarrived Attendance row created ahead of time, so it
 * shows up in the check-in page's back-check-in table and is capacity-gated
 * the same way), but priced via PricingService::applyEventComp() instead of
 * the ordinary price(). Every row here has comp_reason_id set; the Prepay
 * List's query excludes these to keep the two tabs disjoint. See
 * docs/BLUEPRINT.md "Comp list".
 */
class CompListRelationManager extends RelationManager
{
    protected static string $relationship = 'attendance';

    protected static ?string $title = 'Comp list';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'username')
                    ->disabled(),
                Select::make('comp_reason_id')
                    ->label('Reason')
                    ->relationship('compReason', 'name')
                    ->required(),
                TextInput::make('amount_paid')
                    ->label('Amount paid')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                Textarea::make('notes')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->whereNull('checked_in_at')->whereNotNull('comp_reason_id'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member.username')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('compReason.name')
                    ->label('Reason'),
                TextColumn::make('entry_fee')->money()->sortable(),
                TextColumn::make('pool_fee')->money()->sortable(),
                TextColumn::make('amount_paid')->money()->sortable(),
                TextColumn::make('notes')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add to comp list')
                    ->schema([
                        Select::make('member_id')
                            ->label('Member')
                            ->relationship('member', 'username')
                            ->getOptionLabelFromRecordUsing(
                                fn (Member $record) => "{$record->last_name}, {$record->first_name} ({$record->username})"
                            )
                            ->searchable(['username', 'first_name', 'last_name', 'member_number'])
                            ->preload()
                            ->unique(
                                table: 'attendance',
                                column: 'member_id',
                                modifyRuleUsing: fn ($rule) => $rule->where('event_id', $this->getOwnerRecord()->id),
                            )
                            ->rule(function () {
                                return function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (! app(CapacityService::class)->hasRoom($this->getOwnerRecord()->event_date)) {
                                        $fail('The building is at capacity.');
                                    }
                                };
                            })
                            ->required(),
                        Select::make('comp_reason_id')
                            ->label('Reason')
                            ->options(fn () => CompReason::where('active', true)->orderBy('sort_order')->pluck('name', 'id'))
                            ->required(),
                        Textarea::make('notes')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $member = Member::findOrFail($data['member_id']);
                        $breakdown = app(PricingService::class)->applyEventComp(
                            app(PricingService::class)->price($member, $this->getOwnerRecord())
                        );

                        return [
                            ...$data,
                            'checked_in_by' => auth()->id(),
                            'checked_in_at' => null,
                            ...$breakdown->toAttendanceAttributes(),
                        ];
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
