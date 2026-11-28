<?php

namespace App\Filament\Admin\Resources\Events\RelationManagers;

use App\Filament\Concerns\ReadOnlyWhenEventArchived;
use App\Filament\Concerns\RemovesVisits;
use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use App\Models\CompReason;
use App\Models\Member;
use App\Services\AdmissionPolicy;
use App\Services\CapacityService;
use App\Services\CompListImporter;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\PricingService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    use PrunesUploadedFiles;
    use ReadOnlyWhenEventArchived;
    use RemovesVisits;
    use TranslatesRelationManagerTitle;

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
                // Locked: see AttendanceRelationManager.
                TextInput::make('amount_paid')
                    ->label('Amount paid')
                    ->numeric()
                    ->prefix('$')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText(__('Locked once recorded. Payment changes go through an audited correction at the Check-In Desk.')),
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
                            ->getOptionLabelFromRecordUsing(fn (Member $record) => Member::pickerLabel($record))
                            ->searchable(Member::searchableColumns())
                            ->preload()
                            ->unique(
                                table: 'attendance',
                                column: 'member_id',
                                modifyRuleUsing: fn ($rule) => $rule->where('event_id', $this->getOwnerRecord()->id),
                            )
                            ->rule(function () {
                                return function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (! app(CapacityService::class)->hasRoom($this->getOwnerRecord()->event_date)) {
                                        $fail(__('The building is at capacity.'));
                                    }
                                };
                            })
                            // A Block (banned without an exception for this
                            // event, deceased, under age) would only be turned
                            // away at the door, after holding a capacity slot.
                            ->rule(function () {
                                return function (string $attribute, mixed $value, \Closure $fail): void {
                                    $decision = app(AdmissionPolicy::class)->decide(Member::findOrFail($value), $this->getOwnerRecord());

                                    if ($decision->blocksCheckIn()) {
                                        $fail($decision->message);
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
                    })
                    // toAttendanceAttributes() above only covers entry --
                    // each subscribable add-on's line (Pool, at launch,
                    // untouched by the comp) is its own attendance_add_ons
                    // row, same as CheckIn::checkInAction()'s own transaction.
                    ->after(function (Attendance $record): void {
                        $breakdown = app(PricingService::class)->price($record->member, $this->getOwnerRecord());

                        foreach ($breakdown->addOnAttendanceRows() as $row) {
                            AttendanceAddOn::create(['attendance_id' => $record->id, ...$row]);
                        }
                    }),
                $this->downloadCompListTemplateAction(),
                $this->checkCompListFileAction(),
                $this->bulkUploadCompListAction(),
            ])
            ->recordActions([
                EditAction::make(),
                $this->removeVisitAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->deleteUnpaidVisitsBulkAction(),
                ]),
            ]);
    }

    private function canUploadCompList(): bool
    {
        return ! $this->isOwnerEventArchived() && Gate::allows('create', Attendance::class);
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what CompListImporter actually reads.
     */
    protected function downloadCompListTemplateAction(): Action
    {
        return Action::make('downloadCompListTemplate')
            ->label('Download comp list template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->canUploadCompList())
            ->action(function (): StreamedResponse {
                $reason = CompReason::where('active', true)->orderBy('sort_order')->value('name') ?? 'Presenter';

                return response()->streamDownload(function () use ($reason): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['member_number_or_username', 'comp_reason', 'notes']);
                    fputcsv($handle, ['jsmith', $reason, 'Teaching the 9pm class']);
                    fclose($handle);
                }, 'comp-list-upload-template-'.now()->toDateString().'.csv');
            });
    }

    /**
     * Runs the upload's own rules over a file without adding anyone, and
     * downloads every row with the member it matched and what's wrong with
     * it, so a file can be fixed before the real upload.
     */
    protected function checkCompListFileAction(): Action
    {
        return Action::make('checkCompListFile')
            ->label('Check comp list file')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('gray')
            ->modalDescription(__('Nobody is added. You get a results file listing every row, the member it matched, and any problem that would make the upload skip it.'))
            ->modalSubmitActionLabel(__('Check and download results'))
            ->schema([$this->compListFileUpload()])
            ->visible(fn (): bool => $this->canUploadCompList())
            ->action(function (array $data): StreamedResponse {
                abort_unless($this->canUploadCompList(), 403);

                $rows = app(CompListImporter::class)->check($this->getOwnerRecord(), Storage::disk('local')->path($data['file']));
                $this->pruneUploads('comp-list-uploads');

                $problems = collect($rows)->whereNotNull('problem')->count();

                Notification::make()
                    ->title(__(':ok of :total rows would be added; :problems with problems', [
                        'ok' => count($rows) - $problems,
                        'total' => count($rows),
                        'problems' => $problems,
                    ]))
                    ->color($problems ? 'warning' : 'success')
                    ->send();

                return response()->streamDownload(function () use ($rows): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['row', 'member_number_or_username', 'comp_reason', 'notes', 'matched_member', 'result']);

                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row['row'],
                            $row['identifier'],
                            $row['reason'],
                            $row['notes'],
                            $row['member'] ?? '',
                            $row['problem'] ?? 'OK',
                        ]);
                    }

                    fclose($handle);
                }, 'comp-list-upload-check-'.now()->toDateString().'.csv');
            });
    }

    protected function bulkUploadCompListAction(): Action
    {
        return Action::make('bulkUploadCompList')
            ->label('Bulk upload comp list')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalDescription(__('One member per row, with a comp reason. Anyone already on this event\'s list is skipped, so re-uploading a file never adds anyone twice.'))
            ->schema([$this->compListFileUpload()])
            ->visible(fn (): bool => $this->canUploadCompList())
            ->action(function (array $data): void {
                abort_unless($this->canUploadCompList(), 403);

                $path = Storage::disk('local')->path($data['file']);
                $result = app(CompListImporter::class)->import($this->getOwnerRecord(), $path, auth()->user());

                Notification::make()
                    ->title(__('Added :count to the comp list', ['count' => $result['created']]))
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('comp-list-uploads');
            });
    }

    private function compListFileUpload(): FileUpload
    {
        return FileUpload::make('file')
            ->label('Comp list file')
            ->disk('local')
            ->directory('comp-list-uploads')
            ->acceptedFileTypes([
                'text/csv',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->required();
    }
}
