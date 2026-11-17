<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\Event;
use App\Models\EventType;
use App\Services\Analytics\DoorFlowAnalytics;
use App\Services\Analytics\EventAnalytics;
use App\Support\Cents;
use App\Support\CsvDownload;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every event held in the Analytics page's period, side by side: who came,
 * what it took, and how its headcount sits against what that type of event
 * usually draws. The numbers are EventAnalytics'; this only lays them out.
 */
class EventComparisonWidget extends TableWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, int>|null */
    protected ?array $usualHeadcounts = null;

    /** @var array<string, int>|null night => most inside at once */
    protected ?array $peaks = null;

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            // Read in a closure: the page's period can change after this
            // table was built.
            ->query(fn (): Builder => app(EventAnalytics::class)->comparisonQuery($this->period()))
            ->heading(fn (): string => __('Events — :period', ['period' => $this->period()->label()]))
            ->description(__('Events held so far in the period. Private rentals are left out.'))
            ->defaultSort('event_date', 'desc')
            ->recordUrl(fn (Event $record): string => EventResource::getUrl('view', ['record' => $record]))
            ->headerActions([$this->exportAction()])
            ->columns([
                TextColumn::make('event_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Event')
                    ->state(fn (Event $record): string => $this->nameOf($record))
                    ->searchable(),
                TextColumn::make('eventType.name')
                    ->label('Type')
                    ->placeholder('—'),
                TextColumn::make('headcount')
                    ->label('Headcount')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('usual')
                    ->label('Against usual')
                    ->state(fn (Event $record): ?string => $this->againstUsual($record))
                    ->placeholder('—')
                    ->tooltip(__('Headcount against the average for this type of event over the last twelve months.')),
                TextColumn::make('first_timers')
                    ->label('First-timers')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('subscribers')
                    ->label('Subscribers')
                    ->state(fn (Event $record): string => $this->withShare($this->figures($record)['subscribers'], $this->figures($record)['subscriber_percent']))
                    ->sortable(),
                TextColumn::make('comps')
                    ->label('Comps')
                    ->state(fn (Event $record): string => $this->withShare($this->figures($record)['comps'], $this->figures($record)['comp_percent']))
                    ->sortable(),
                TextColumn::make('night_peak')
                    ->label('Most inside')
                    ->state(fn (Event $record): ?int => $this->peakOf($record))
                    ->placeholder('—')
                    ->tooltip(__('The most people inside at once that night, every event on it together. Blank for a night not checked in through the app.')),
                TextColumn::make('no_shows')
                    ->label('Prepaid, no-show')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('revenue')
                    ->label('Revenue')
                    ->state(fn (Event $record): float => Cents::toFloat($this->figures($record)['revenue_cents']))
                    ->money()
                    ->sortable(),
                TextColumn::make('per_head')
                    ->label('Per head')
                    ->state(fn (Event $record): ?float => ($cents = $this->figures($record)['per_head_cents']) === null ? null : Cents::toFloat($cents))
                    ->money()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('event_type_id')
                    ->label('Type')
                    ->options(fn (): array => EventType::orderBy('sort_order')->pluck('name', 'id')->all()),
            ]);
    }

    /**
     * Exactly what the table shows -- same period, filter, search and sort,
     * every page of it -- with bare numbers so a spreadsheet can sum them.
     */
    protected function exportAction(): Action
    {
        return Action::make('export')
            ->label('Export (CSV)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(fn (): StreamedResponse => CsvDownload::stream(
                'event-comparison-'.now()->toDateString().'.csv',
                [
                    'Date', 'Event', 'Type', 'Headcount', 'Usual Headcount', 'First-timers', 'Subscribers',
                    'Comps', 'Prepaid No-show', 'Revenue', 'Revenue Per Head', 'Most Inside',
                ],
                $this->getTableQueryForExport()->get()->map(function (Event $event): array {
                    $figures = $this->figures($event);

                    return [
                        $event->event_date->toDateString(),
                        $this->nameOf($event),
                        $event->eventType?->name,
                        $figures['headcount'],
                        $figures['usual_headcount'],
                        $figures['first_timers'],
                        $figures['subscribers'],
                        $figures['comps'],
                        $figures['no_shows'],
                        Cents::toDecimal($figures['revenue_cents']),
                        $figures['per_head_cents'] === null ? null : Cents::toDecimal($figures['per_head_cents']),
                        $this->peakOf($event),
                    ];
                }),
            ));
    }

    /**
     * @return array{headcount: int, no_shows: int, revenue_cents: int, per_head_cents: ?int, comps: int, comp_percent: ?int, subscribers: int, subscriber_percent: ?int, first_timers: int, usual_headcount: ?int}
     */
    private function figures(Event $event): array
    {
        $analytics = app(EventAnalytics::class);

        return $analytics->figures($event, $this->usualHeadcounts ??= $analytics->usualHeadcounts($this->period()));
    }

    /**
     * The most people inside at once on the event's night (DoorFlowAnalytics),
     * shared by every event that night.
     */
    private function peakOf(Event $event): ?int
    {
        $this->peaks ??= app(DoorFlowAnalytics::class)->peaks($this->period());

        return $this->peaks[$event->event_date->toDateString()] ?? null;
    }

    private function nameOf(Event $event): string
    {
        return $event->name ?? $event->eventType?->name ?? __('Untitled event');
    }

    /**
     * "+5 (usual 37)": above or below what this type of event usually draws.
     */
    private function againstUsual(Event $event): ?string
    {
        $figures = $this->figures($event);

        if ($figures['usual_headcount'] === null) {
            return null;
        }

        $difference = $figures['headcount'] - $figures['usual_headcount'];

        return __(':difference (usual :usual)', [
            'difference' => $difference > 0 ? '+'.$difference : (string) $difference,
            'usual' => $figures['usual_headcount'],
        ]);
    }

    private function withShare(int $count, ?int $percent): string
    {
        return $percent === null ? (string) $count : __(':count (:percent%)', ['count' => $count, 'percent' => $percent]);
    }
}
