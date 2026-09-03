<?php

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\PaymentMethod;
use App\Services\CapacityService;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\PrepayListImporter;
use App\Services\PricingService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The club's external-payment prepay list for a specific event (matching a
 * Venmo/PayPal/etc. payment to a member ahead of the night) — distinct from
 * the general Attendance tab, which covers same-night arrivals. Every row
 * here is a prepay by definition (checked_in_at is always null); no
 * checked_in_at field on this form at all. See
 * docs/BLUEPRINT.md "Prepay events".
 */
class PrepayListRelationManager extends RelationManager
{
    use PrunesUploadedFiles;

    protected static string $relationship = 'attendance';

    protected static ?string $title = 'Prepay list';

    // No dedicated Policy exists for this relation manager (it manages
    // Attendance rows, not a standalone resource) -- Filament's own
    // canViewForRecord() hook is the equivalent single gate point, same
    // shape as every other round's Policy::viewAny() override.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && MembershipSetting::current()->prepay_enabled;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'username')
                    ->disabled(),
                TextInput::make('amount_paid')
                    ->label('Amount paid')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                Select::make('payment_method')
                    ->options(PaymentMethod::options()),
                Textarea::make('notes')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->whereNull('checked_in_at')->whereNull('comp_reason_id'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('member.username')
                    ->label('Member')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('entry_fee')->money()->sortable(),
                TextColumn::make('pool_fee')->money()->sortable(),
                TextColumn::make('amount_paid')->money()->sortable(),
                TextColumn::make('payment_method')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add to prepay list')
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
                        TextInput::make('amount_override')
                            ->label('Amount actually paid (optional override)')
                            ->numeric()
                            ->helperText('Leave blank to use the computed price. Set this for a partial deposit or an early-bird rate.'),
                        TextInput::make('payment_method')
                            ->maxLength(30),
                        Textarea::make('notes')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->mutateFormDataUsing(function (array $data): array {
                        $member = Member::findOrFail($data['member_id']);
                        $breakdown = app(PricingService::class)->price($member, $this->getOwnerRecord());
                        $attrs = $breakdown->toAttendanceAttributes();

                        if (filled($data['amount_override'] ?? null)) {
                            $attrs['amount_paid'] = $data['amount_override'];
                        }

                        return [
                            ...$data,
                            'checked_in_by' => auth()->id(),
                            'checked_in_at' => null,
                            ...$attrs,
                        ];
                    }),
                $this->bulkUploadAction(),
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

    protected function bulkUploadAction(): Action
    {
        return Action::make('bulkUploadPrepay')
            ->label('Bulk upload prepay list')
            ->schema([
                FileUpload::make('file')
                    ->label('Prepay list file')
                    ->disk('local')
                    ->directory('prepay-uploads')
                    ->acceptedFileTypes([
                        'text/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->action(function (array $data): void {
                $path = Storage::disk('local')->path($data['file']);
                $result = app(PrepayListImporter::class)->import($this->getOwnerRecord(), $path, auth()->user());

                Notification::make()
                    ->title("Added {$result['created']} to the prepay list")
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('prepay-uploads');
            });
    }
}
