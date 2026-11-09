<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\RoleTask;
use App\Models\RoleTaskCompletion;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use App\Models\TaskArea;
use App\Services\RoleTaskProposalService;
use App\Services\RoleTaskService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * The task lists for the roles someone works: each shift of theirs that's
 * running now (and other running shifts of roles they can work, where a
 * tick counts as extra), and the weekly list of a role that isn't
 * scheduled (Cleaning). History lists what they've done. The rules are in
 * RoleTaskService.
 */
class MyTasks extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.my-tasks';

    protected static ?string $slug = 'my-tasks';

    protected static ?string $title = 'My tasks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 2;

    /** 'tasks' (what to do now) or 'history' (what you've done). */
    public string $display = 'tasks';

    public static function canAccess(): bool
    {
        return Gate::allows('work-role-tasks');
    }

    /**
     * Each list on screen, shaped for the view.
     *
     * @return list<array{title: string, meta: string, extra: bool|null, slotId: int|null, areas: list<array{name: string|null, notes: string|null, tasks: list<array{id: int, name: string, description: string|null, done: string|null, completionId: int|null, canUndo: bool}>}>}>
     */
    public function lists(): array
    {
        $user = auth()->user();
        $service = $this->service();

        $shifts = $service->currentShiftsFor($user)->map(fn (ShiftSlot $slot): array => [
            'title' => $slot->displayName(),
            'meta' => __(':event, :start to :end', ['event' => $slot->event->name, 'start' => $slot->starts_at->isoFormat('ddd LT'), 'end' => $slot->ends_at->isoFormat('LT')]),
            'extra' => ! $service->isOnShift($user, $slot),
            'slotId' => $slot->id,
            'areas' => $this->areas($slot->shiftRole, $slot),
        ]);

        $weekly = $service->weeklyRolesFor($user)->map(fn (ShiftRole $role): array => [
            'title' => $role->name,
            'meta' => __('This week, from :start', ['start' => $service->weekStart()->isoFormat('ddd ll')]),
            'extra' => null,
            'slotId' => null,
            'areas' => $this->areas($role, null),
        ]);

        return [...$shifts->all(), ...$weekly->all()];
    }

    /**
     * @return list<array{name: string|null, notes: string|null, tasks: list<array<string, mixed>>}>
     */
    protected function areas(ShiftRole $role, ?ShiftSlot $slot): array
    {
        $done = $this->service()->completionsFor($role, $slot);

        return $this->service()->tasksFor($role)
            ->groupBy(fn (RoleTask $task): string => (string) $task->task_area_id)
            ->map(fn (Collection $tasks): array => [
                'name' => $tasks->first()->area?->name,
                'notes' => $tasks->first()->area?->notes,
                'tasks' => $tasks->map(function (RoleTask $task) use ($done): array {
                    $completion = $done->get($task->id);

                    return [
                        'id' => $task->id,
                        'name' => $task->name,
                        'description' => $task->description,
                        'done' => $completion === null ? null : __('Done by :name, :time', ['name' => $completion->user?->name, 'time' => $completion->completed_at->isoFormat('ddd LT')]),
                        'completionId' => $completion?->id,
                        'canUndo' => $completion !== null && $completion->user_id === auth()->id(),
                    ];
                })->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function tick(int $taskId, ?int $slotId = null): void
    {
        abort_unless(static::canAccess(), 403);

        $task = RoleTask::with('shiftRole')->find($taskId);
        $slot = $slotId === null ? null : ShiftSlot::with('assignments')->find($slotId);

        if ($task === null) {
            return;
        }

        try {
            $completion = $this->service()->complete(auth()->user(), $task, $slot);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();

            return;
        }

        Notification::make()
            ->title(__('Done: :task', ['task' => $task->name]))
            ->body($completion->on_shift === false ? __('You\'re not on this shift, so it\'s counted as extra.') : null)
            ->success()
            ->send();
    }

    public function undo(int $completionId): void
    {
        abort_unless(static::canAccess(), 403);

        $completion = RoleTaskCompletion::with('shiftSlot')->find($completionId);

        if ($completion === null) {
            return;
        }

        try {
            $this->service()->undo(auth()->user(), $completion);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => RoleTaskCompletion::query()
                ->with(['task.shiftRole', 'shiftSlot.event'])
                ->where('user_id', auth()->id()))
            ->defaultSort('completed_at', 'desc')
            ->columns([
                TextColumn::make('completed_at')
                    ->label(__('When'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('task.shiftRole.name')
                    ->label(__('Role')),
                TextColumn::make('task.name')
                    ->label(__('Task'))
                    ->searchable(),
                TextColumn::make('where')
                    ->label(__('Shift'))
                    ->state(fn (RoleTaskCompletion $record): string => $record->shiftSlot
                        ? __(':shift, :event', ['shift' => $record->shiftSlot->displayName(), 'event' => $record->shiftSlot->event?->name])
                        : ($record->week_start ? __('Week of :date', ['date' => $record->week_start->isoFormat('ll')]) : __('Shift removed'))),
                TextColumn::make('on_shift')
                    ->label(__('Extra'))
                    ->state(fn (RoleTaskCompletion $record): ?string => $record->on_shift === false ? __('Off shift') : null)
                    ->badge()
                    ->color('success')
                    ->icon(Heroicon::OutlinedStar),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggleDisplay')
                ->label(fn (): string => $this->display === 'history' ? __('Tasks now') : __('History'))
                ->icon(fn (): Heroicon => $this->display === 'history' ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedClock)
                ->color('gray')
                ->action(fn () => $this->display = $this->display === 'history' ? 'tasks' : 'history'),
            $this->proposeAction(),
        ];
    }

    /**
     * Suggest a task for one of your roles; the others who work it vote,
     * and a Manager+ decides (RoleTaskProposalService).
     */
    protected function proposeAction(): Action
    {
        return Action::make('propose')
            ->label(__('Propose a task'))
            ->icon(Heroicon::OutlinedLightBulb)
            ->modalDescription(__('Others who work the role can vote on it, and a Manager decides whether it joins the standard list.'))
            ->schema([
                Select::make('shift_role_id')
                    ->label(__('Shift role'))
                    ->options(fn (): array => $this->proposals()->rolesFor(auth()->user())->pluck('name', 'id')->all())
                    ->required(),
                Select::make('task_area_id')
                    ->label(__('Area'))
                    ->options(fn (): array => TaskArea::where('active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder(__('No area')),
                TextInput::make('name')
                    ->label(__('Task'))
                    ->required()
                    ->maxLength(100),
                TextInput::make('description')
                    ->label(__('Details'))
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                abort_unless(static::canAccess(), 403);

                $role = ShiftRole::find($data['shift_role_id']);
                abort_if($role === null, 404);

                $this->proposals()->propose(
                    auth()->user(),
                    $role,
                    $data['name'],
                    $data['description'] ?? null,
                    filled($data['task_area_id'] ?? null) ? TaskArea::find($data['task_area_id']) : null,
                );

                Notification::make()->title(__('Proposed'))->body(__('A Manager will decide, after the others who work the role have had a chance to vote.'))->success()->send();
            });
    }

    /**
     * Open proposals for your roles, shaped for the view.
     *
     * @return list<array{id: int, role: string, name: string, description: string|null, area: string|null, by: string, up: int, down: int, mine: bool, myVote: int}>
     */
    public function openProposals(): array
    {
        $user = auth()->user();

        return $this->proposals()->openFor($user)->map(function (RoleTask $task) use ($user): array {
            $tally = $this->proposals()->tally($task);

            return [
                'id' => $task->id,
                'role' => $task->shiftRole->name,
                'name' => $task->name,
                'description' => $task->description,
                'area' => $task->area?->name,
                'by' => $task->proposedBy?->name ?? __('Someone no longer on staff'),
                'up' => $tally['up'],
                'down' => $tally['down'],
                'mine' => $task->proposed_by === $user->id,
                'myVote' => (int) $task->votes->firstWhere('user_id', $user->id)?->vote,
            ];
        })->all();
    }

    /**
     * Pressing the vote you already gave takes it back.
     */
    public function vote(int $taskId, int $vote): void
    {
        abort_unless(static::canAccess(), 403);

        $task = RoleTask::with(['shiftRole', 'votes'])->find($taskId);

        if ($task === null) {
            return;
        }

        $current = (int) $task->votes->firstWhere('user_id', auth()->id())?->vote;

        try {
            $this->proposals()->vote(auth()->user(), $task, $current === $vote ? 0 : $vote);
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->validator->errors()->first())->danger()->send();
        }
    }

    protected function proposals(): RoleTaskProposalService
    {
        return app(RoleTaskProposalService::class);
    }

    protected function service(): RoleTaskService
    {
        return app(RoleTaskService::class);
    }
}
