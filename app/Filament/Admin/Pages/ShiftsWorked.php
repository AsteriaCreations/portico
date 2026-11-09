<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\User;
use App\Services\ShiftWorkService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
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
 * Manager+ view of each staff member's shifts worked in a month against
 * the club's monthly requirement: who's short, who's met it, and who went
 * beyond it. Counting is in ShiftWorkService.
 */
class ShiftsWorked extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.shifts-worked';

    protected static ?string $slug = 'shifts-worked';

    protected static ?string $title = 'Shifts Worked';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Staff';

    protected static ?int $navigationSort = 5;

    /** The month on screen, as Y-m. */
    public string $month = '';

    /** @var array{month: string, counts: array<int, int>}|null */
    protected ?array $countCache = null;

    public static function canAccess(): bool
    {
        return Gate::allows('record-shifts-worked');
    }

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function getSubheading(): string
    {
        $requirement = app(ShiftWorkService::class)->requirement();

        return $this->monthStart()->isoFormat('MMMM YYYY').' · '.($requirement > 0
            ? trans_choice('Requirement: :count shift a month|Requirement: :count shifts a month', $requirement)
            : __('No monthly requirement set'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $active) => $active->where('active', true)->whereHas('shiftRoles', fn (Builder $role) => $role->where('active', true)))
                    ->orWhereIn('id', array_keys($this->counts()))))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('worked')
                    ->label(__('Shifts worked'))
                    ->state(fn (User $record): int => $this->counts()[$record->id] ?? 0)
                    ->numeric(),
                TextColumn::make('status')
                    ->label(__('Requirement'))
                    ->state(fn (User $record): string => $this->statusLabel($this->counts()[$record->id] ?? 0))
                    ->badge()
                    ->color(fn (User $record): string => match ($this->status($this->counts()[$record->id] ?? 0)) {
                        'short' => 'warning',
                        'none' => 'gray',
                        default => 'success',
                    })
                    ->icon(fn (User $record): Heroicon => match ($this->status($this->counts()[$record->id] ?? 0)) {
                        'short' => Heroicon::OutlinedExclamationTriangle,
                        'none' => Heroicon::OutlinedMinus,
                        default => Heroicon::OutlinedCheck,
                    }),
                TextColumn::make('beyond')
                    ->label(__('Beyond the requirement'))
                    ->state(fn (User $record): int => $this->beyond($this->counts()[$record->id] ?? 0))
                    ->numeric(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Requirement'))
                    ->options([
                        'short' => __('Short'),
                        'met' => __('Met'),
                        'beyond' => __('Beyond'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $requirement = app(ShiftWorkService::class)->requirement();
                        $counts = $this->counts();
                        $matching = array_keys(array_filter($counts, fn (int $worked): bool => match ($data['value']) {
                            'beyond' => $worked > $requirement,
                            'met' => $worked >= $requirement,
                            default => $worked < $requirement,
                        }));

                        // Nobody with no shifts is in $counts; they're short (when there's a requirement).
                        return $data['value'] === 'short'
                            ? ($requirement > 0 ? $query->whereNotIn('id', array_diff(array_keys($counts), $matching)) : $query->whereRaw('1 = 0'))
                            : $query->whereIn('id', $matching);
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousMonth')
                ->label(__('Previous month'))
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->action(fn () => $this->moveMonth(-1)),
            Action::make('nextMonth')
                ->label(__('Next month'))
                ->icon(Heroicon::OutlinedChevronRight)
                ->iconPosition('after')
                ->color('gray')
                ->disabled(fn (): bool => $this->monthStart()->gte(now()->startOfMonth()))
                ->action(fn () => $this->moveMonth(1)),
        ];
    }

    public function moveMonth(int $months): void
    {
        $target = $this->monthStart()->addMonths($months);

        // No months ahead of this one: nothing's been worked yet.
        $this->month = ($target->gt(now()->startOfMonth()) ? now()->startOfMonth() : $target)->format('Y-m');
        $this->resetTable();
    }

    /**
     * @return array<int, int>
     */
    protected function counts(): array
    {
        if ($this->countCache === null || $this->countCache['month'] !== $this->month) {
            $this->countCache = ['month' => $this->month, 'counts' => app(ShiftWorkService::class)->workedCountsFor($this->monthStart())];
        }

        return $this->countCache['counts'];
    }

    protected function status(int $worked): string
    {
        $requirement = app(ShiftWorkService::class)->requirement();

        return match (true) {
            $requirement === 0 => 'none',
            $worked > $requirement => 'beyond',
            $worked >= $requirement => 'met',
            default => 'short',
        };
    }

    protected function statusLabel(int $worked): string
    {
        $requirement = app(ShiftWorkService::class)->requirement();

        return match ($this->status($worked)) {
            'none' => __('No requirement'),
            'short' => trans_choice('Short by :count|Short by :count', $requirement - $worked),
            default => __('Met'),
        };
    }

    protected function beyond(int $worked): int
    {
        $requirement = app(ShiftWorkService::class)->requirement();

        return $requirement > 0 ? max(0, $worked - $requirement) : 0;
    }

    protected function monthStart(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m', $this->month ?: now()->format('Y-m'))->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }
}
