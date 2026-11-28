<?php

namespace App\Filament\Admin\Pages;

use App\Enums\ShiftKind;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Services\Concerns\PrunesUploadedFiles;
use App\Services\ShiftSlotBulkImporter;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Manager+ staffs each event here, one schedule week at a time: the week's
 * events with a summary of their shifts, and the picked event's shifts
 * below (ShiftSlotsRelationManager, embedded) to set its standard shifts,
 * add setup or extra shifts, and record who worked. Kept with the rest of
 * staff scheduling rather than as a tab on each event.
 */
class Staffing extends Page implements HasTable
{
    use InteractsWithTable;
    use PrunesUploadedFiles;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.staffing';

    protected static ?string $slug = 'staffing';

    protected static ?string $title = 'Staffing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 3;

    /** The first day (Y-m-d) of the schedule week on screen. */
    #[Url(as: 'week')]
    public string $weekStart = '';

    /** The event whose shifts are open below; checked in selectedEvent(). */
    #[Url(as: 'event')]
    public ?int $eventId = null;

    public static function canAccess(): bool
    {
        // viewAny carries the staff scheduling flag; create, Manager+.
        return Gate::allows('viewAny', ShiftSlot::class) && Gate::allows('create', ShiftSlot::class);
    }

    public function mount(): void
    {
        $event = $this->eventId ? Event::find($this->eventId) : null;

        // A link to an event opens its week; otherwise the week ahead,
        // when staffing is usually set.
        try {
            $week = $event?->starts_at ?? $event?->event_date ?? ($this->weekStart !== '' ? CarbonImmutable::parse($this->weekStart) : now()->addWeek());
        } catch (\Throwable) {
            $week = now()->addWeek();
        }

        $this->weekStart = $this->scheduleWeek($week)->toDateString();
    }

    public function getSubheading(): string
    {
        $start = $this->week();

        return __('Week of :start to :end', [
            'start' => $start->isoFormat('ddd ll'),
            'end' => $start->addDays(6)->isoFormat('ddd ll'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->weekEvents())
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['shiftSlots' => fn ($slots) => $slots->with('shiftRole')->withCount('assignments')]))
            ->defaultSort('starts_at')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label(__('Event'))
                    ->weight(fn (Event $record): ?string => $record->id === $this->eventId ? 'bold' : null)
                    ->description(fn (Event $record): ?string => $record->id === $this->eventId ? __('Open below') : null),
                TextColumn::make('starts_at')
                    ->label(__('Starts'))
                    ->dateTime('D M j, g:i A')
                    ->placeholder(fn (Event $record): string => $record->event_date?->isoFormat('ddd ll') ?? ''),
                TextColumn::make('shifts')
                    ->label(__('Shifts'))
                    ->state(fn (Event $record): string => $this->summary($record))
                    ->wrap(),
                TextColumn::make('filled')
                    ->label(__('Scheduled'))
                    ->state(fn (Event $record): string => __(':filled of :needed', [
                        'filled' => $record->shiftSlots->sum('assignments_count'),
                        'needed' => $record->shiftSlots->sum('headcount'),
                    ])),
            ])
            ->recordAction('staff')
            ->recordActions([
                Action::make('staff')
                    ->label(__('Staff it'))
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->action(fn (Event $record) => $this->eventId = $record->id),
            ])
            ->emptyStateHeading(__('No events this week'))
            ->emptyStateDescription(__('Events are added on Events. Use Next week to staff further ahead.'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousWeek')
                ->label(__('Previous week'))
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->action(fn () => $this->moveWeek(-1)),
            Action::make('nextWeek')
                ->label(__('Next week'))
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition('after')
                ->color('gray')
                ->action(fn () => $this->moveWeek(1)),
            ActionGroup::make([
                $this->downloadShiftTemplateAction(),
                $this->checkShiftFileAction(),
                $this->bulkUploadShiftsAction(),
            ])
                ->label(__('Upload shifts'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * Generated on the fly rather than a static file, so the columns can
     * never drift from what ShiftSlotBulkImporter actually reads.
     */
    protected function downloadShiftTemplateAction(): Action
    {
        return Action::make('downloadShiftTemplate')
            ->label('Download shift template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => Gate::allows('create', ShiftSlot::class))
            ->action(function (): StreamedResponse {
                $role = ShiftRole::query()->scheduled()->orderBy('sort_order')->orderBy('name')->value('name') ?? 'Setup';
                $event = Event::whereNull('archived_at')->whereDate('event_date', '>=', today())->orderBy('event_date')->first();
                $date = $event?->event_date ?? today();
                $starts = $event?->starts_at ?? $date->copy()->setTime(20, 0);

                return response()->streamDownload(function () use ($role, $event, $date, $starts): void {
                    $handle = fopen('php://output', 'w');
                    fputcsv($handle, ['event_date', 'event_name', 'shift_role', 'kind', 'label', 'starts_at', 'ends_at', 'people_needed', 'notes']);
                    fputcsv($handle, [$date->toDateString(), $event?->name ?? '', $role, 'setup', 'Bar setup', $starts->copy()->subHours(3)->format('Y-m-d H:i'), $starts->format('Y-m-d H:i'), '2', '']);
                    fclose($handle);
                }, 'shift-upload-template-'.now()->toDateString().'.csv');
            });
    }

    /**
     * Runs the upload's own rules over a file without adding anything, and
     * downloads every row with the event it matched and what's wrong with
     * it, so a file can be fixed before the real upload.
     */
    protected function checkShiftFileAction(): Action
    {
        return Action::make('checkShiftFile')
            ->label('Check shift file')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->modalDescription(__('Nothing is added. You get a results file listing every row, the event it matched, and any problem that would make the upload skip it.'))
            ->modalSubmitActionLabel(__('Check and download results'))
            ->schema([$this->shiftFileUpload()])
            ->visible(fn (): bool => Gate::allows('create', ShiftSlot::class))
            ->action(function (array $data): StreamedResponse {
                abort_unless(Gate::allows('create', ShiftSlot::class), 403);

                $rows = app(ShiftSlotBulkImporter::class)->check(Storage::disk('local')->path($data['file']));
                $this->pruneUploads('shift-uploads');

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
                    fputcsv($handle, ['row', 'event_date', 'event_name', 'shift_role', 'kind', 'label', 'starts_at', 'ends_at', 'people_needed', 'notes', 'matched_event', 'result']);

                    foreach ($rows as $row) {
                        fputcsv($handle, [
                            $row['row'],
                            $row['event_date'],
                            $row['event_name'],
                            $row['role'],
                            $row['kind'],
                            $row['label'],
                            $row['starts_at'],
                            $row['ends_at'],
                            $row['headcount'],
                            $row['notes'],
                            $row['event'] ?? '',
                            $row['problem'] ?? 'OK',
                        ]);
                    }

                    fclose($handle);
                }, 'shift-upload-check-'.now()->toDateString().'.csv');
            });
    }

    protected function bulkUploadShiftsAction(): Action
    {
        return Action::make('bulkUploadShifts')
            ->label('Bulk upload shifts')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->modalDescription(__('Adds setup and extra shifts to any events, one shift per row. Standard shifts are still set on each event. A shift already on its event is skipped, so re-uploading a file never adds anything twice.'))
            ->schema([$this->shiftFileUpload()])
            ->visible(fn (): bool => Gate::allows('create', ShiftSlot::class))
            ->action(function (array $data): void {
                abort_unless(Gate::allows('create', ShiftSlot::class), 403);

                $result = app(ShiftSlotBulkImporter::class)->import(Storage::disk('local')->path($data['file']));

                Notification::make()
                    ->title(trans_choice('Added :count shift|Added :count shifts', $result['created']))
                    ->body($result['log'] ? implode("\n", $result['log']) : null)
                    ->success()
                    ->send();

                $this->pruneUploads('shift-uploads');
                $this->resetTable();
            });
    }

    private function shiftFileUpload(): FileUpload
    {
        return FileUpload::make('file')
            ->label('Shifts file')
            ->disk('local')
            ->directory('shift-uploads')
            ->acceptedFileTypes([
                'text/csv',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->required();
    }

    public function moveWeek(int $weeks): void
    {
        $this->weekStart = $this->week()->addWeeks($weeks)->toDateString();
        $this->eventId = null;
        $this->resetTable();
    }

    /**
     * The event picked below, only if it's one of this week's (so a forged
     * id can't open some other event).
     */
    public function selectedEvent(): ?Event
    {
        return $this->eventId === null ? null : $this->weekEvents()->whereKey($this->eventId)->first();
    }

    /**
     * "Open: Desk 2, DM 1 · Close: Desk 1 · Setup 4": each kind with its
     * roles' head counts.
     */
    protected function summary(Event $event): string
    {
        if ($event->shiftSlots->isEmpty()) {
            return __('No shifts yet');
        }

        return $event->shiftSlots
            ->sortBy(fn (ShiftSlot $slot): string => array_search($slot->kind, ShiftKind::cases(), true).$slot->starts_at->format('YmdHi'))
            ->groupBy(fn (ShiftSlot $slot): string => $slot->kind->isStandard() ? $slot->kind->getLabel() : ($slot->label ?: $slot->kind->getLabel()))
            ->map(fn ($slots, string $group): string => __(':group: :roles', [
                'group' => $group,
                'roles' => $slots->map(fn (ShiftSlot $slot): string => $slot->shiftRole?->name.' '.$slot->headcount)->implode(', '),
            ]))
            ->implode(' · ');
    }

    /**
     * Unarchived events starting in the week on screen (by start time, or
     * date when there's none).
     */
    protected function weekEvents(): Builder
    {
        $start = $this->week();
        $end = $start->addWeek();

        return Event::query()
            ->whereNull('archived_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $timed) => $timed->where('starts_at', '>=', $start)->where('starts_at', '<', $end))
                ->orWhere(fn (Builder $dated) => $dated->whereNull('starts_at')->whereDate('event_date', '>=', $start->toDateString())->whereDate('event_date', '<', $end->toDateString())));
    }

    protected function week(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->weekStart ?: $this->scheduleWeek(now()->addWeek())->toDateString());
    }

    protected function scheduleWeek(\DateTimeInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance(MembershipSetting::startOfScheduleWeek(CarbonImmutable::instance($date))->startOfDay());
    }
}
