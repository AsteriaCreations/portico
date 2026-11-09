<?php

namespace App\Filament\Admin\Pages;

use App\Enums\RoleTaskStatus;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\RoleTask;
use App\Models\TaskArea;
use App\Services\RoleTaskProposalService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Manager+ decides the tasks staff proposed for their roles, seeing how
 * the others who work the role voted. Approving puts it on the role's
 * standard list; declining keeps it here with the reason. See
 * RoleTaskProposalService.
 */
class TaskProposals extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.task-proposals';

    protected static ?string $slug = 'task-proposals';

    protected static ?string $title = 'Task proposals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 7;

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', RoleTask::class);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = RoleTask::where('status', RoleTaskStatus::Proposed)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Waiting for a decision');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => RoleTask::query()
                ->with(['shiftRole', 'area', 'proposedBy', 'decidedBy'])
                ->withCount(['votes as up_votes' => fn (Builder $query) => $query->where('vote', 1), 'votes as down_votes' => fn (Builder $query) => $query->where('vote', -1)])
                // Everything staff proposed, decided or not.
                ->where(fn (Builder $query) => $query->whereNotNull('proposed_by')->orWhere('status', '!=', RoleTaskStatus::Standard)))
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('shiftRole.name')
                    ->label(__('Shift role')),
                TextColumn::make('name')
                    ->label(__('Task'))
                    ->description(fn (RoleTask $record): ?string => $record->description)
                    ->searchable(),
                TextColumn::make('proposedBy.name')
                    ->label(__('Proposed by'))
                    ->description(fn (RoleTask $record): string => $record->created_at->isoFormat('ll')),
                TextColumn::make('up_votes')
                    ->label(__('Up'))
                    ->numeric()
                    ->icon(Heroicon::OutlinedHandThumbUp)
                    ->sortable(),
                TextColumn::make('down_votes')
                    ->label(__('Down'))
                    ->numeric()
                    ->icon(Heroicon::OutlinedHandThumbDown)
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (RoleTaskStatus $state): string => match ($state) {
                        RoleTaskStatus::Proposed => 'warning',
                        RoleTaskStatus::Standard => 'success',
                        RoleTaskStatus::Declined => 'gray',
                    })
                    ->description(fn (RoleTask $record): ?string => $record->status === RoleTaskStatus::Proposed ? null : trim(($record->decidedBy?->name ?? '').' '.($record->decline_reason ?? ''))),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(RoleTaskStatus::class)
                    ->default(RoleTaskStatus::Proposed->value),
                SelectFilter::make('shift_role_id')
                    ->label(__('Shift role'))
                    ->relationship('shiftRole', 'name'),
            ])
            ->recordActions([$this->approveAction(), $this->declineAction()]);
    }

    protected function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (RoleTask $record): bool => $record->status === RoleTaskStatus::Proposed)
            ->modalHeading(fn (RoleTask $record): string => __('Add ":task" to the :role list', ['task' => $record->name, 'role' => $record->shiftRole->name]))
            ->fillForm(fn (RoleTask $record): array => ['task_area_id' => $record->task_area_id, 'sort_order' => 0])
            ->schema([
                Select::make('task_area_id')
                    ->label(__('Area'))
                    ->options(fn (): array => TaskArea::where('active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder(__('No area')),
                TextInput::make('sort_order')
                    ->label(__('Sort order'))
                    ->numeric()
                    ->integer()
                    ->required(),
            ])
            ->action(function (RoleTask $record, array $data): void {
                abort_unless(static::canAccess(), 403);

                $area = filled($data['task_area_id'] ?? null) ? TaskArea::find($data['task_area_id']) : null;
                app(RoleTaskProposalService::class)->approve(auth()->user(), $record, $area, (int) $data['sort_order']);

                Notification::make()->title(__('Added to the list'))->success()->send();
            });
    }

    protected function declineAction(): Action
    {
        return Action::make('decline')
            ->label(__('Decline'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->visible(fn (RoleTask $record): bool => $record->status === RoleTaskStatus::Proposed)
            ->modalHeading(fn (RoleTask $record): string => __('Decline ":task"', ['task' => $record->name]))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Why'))
                    ->helperText(__('The person who proposed it is told this.'))
                    ->required()
                    ->maxLength(255)
                    ->rows(2),
            ])
            ->action(function (RoleTask $record, array $data): void {
                abort_unless(static::canAccess(), 403);

                app(RoleTaskProposalService::class)->decline(auth()->user(), $record, $data['reason']);

                Notification::make()->title(__('Declined'))->success()->send();
            });
    }
}
