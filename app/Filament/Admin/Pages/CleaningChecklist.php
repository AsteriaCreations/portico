<?php

namespace App\Filament\Admin\Pages;

use App\Models\CleaningTask;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The Cleaning Crew's recurring weekly checklist -- gated entirely by the
 * access-cleaning-checklist Gate (App\Enums\Capability::CleaningCrew), never
 * by role. Different crew members complete different individual tasks off
 * the same list, so completion is tracked per task, not as one "whole list
 * done" action -- each task resets independently at the start of a new
 * calendar week (now()->startOfWeek(), the same convention this app's
 * "weekly" widgets already use).
 */
class CleaningChecklist extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.admin.pages.cleaning-checklist';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Cleaning Checklist';

    protected static string|UnitEnum|null $navigationGroup = 'Front of House';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        return Gate::allows('access-cleaning-checklist');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CleaningTask::query()->where('active', true))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('description'),
                IconColumn::make('completed_this_week')
                    ->label('Done this week')
                    ->boolean()
                    ->getStateUsing(fn (CleaningTask $record): bool => $record->isCompletedForWeek(now()->startOfWeek()))
                    ->tooltip(fn (bool $state): string => $state ? 'Completed this week' : 'Not yet completed this week'),
                TextColumn::make('completed_by')
                    ->label('Completed by')
                    ->getStateUsing(fn (CleaningTask $record) => $record->completions()
                        ->whereDate('for_week_start', now()->startOfWeek())
                        ->first()?->completedBy?->name),
            ])
            ->recordActions([$this->completeAction()]);
    }

    public function completeAction(): Action
    {
        return Action::make('complete')
            ->label(fn (CleaningTask $record): string => $record->isCompletedForWeek(now()->startOfWeek()) ? 'Completed' : 'Mark done')
            ->disabled(fn (CleaningTask $record): bool => $record->isCompletedForWeek(now()->startOfWeek()))
            ->action(function (CleaningTask $record): void {
                // Re-checked here, not just via canAccess() gating the page —
                // same defensive pattern as ActivePatrons::departAction().
                abort_unless(Gate::allows('access-cleaning-checklist'), 403);

                if (! $record->isCompletedForWeek(now()->startOfWeek())) {
                    $record->completions()->create([
                        'completed_by' => auth()->id(),
                        'for_week_start' => now()->startOfWeek()->toDateString(),
                    ]);
                }

                Notification::make()->title('Marked complete')->success()->send();
            });
    }
}
