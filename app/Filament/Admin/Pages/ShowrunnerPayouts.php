<?php

namespace App\Filament\Admin\Pages;

use App\Enums\EntryCoverageSource;
use App\Enums\Role;
use App\Filament\Admin\Resources\Events\EventResource;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Models\AddOn;
use App\Models\Event;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\Plan;
use App\Models\ShowrunnerPayoutTier;
use App\Services\ShowrunnerPayoutBand;
use App\Services\ShowrunnerPayoutResult;
use App\Services\ShowrunnerPayoutService;
use App\Support\Cents;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Every past event's showrunner commission in one list, for checking what is
 * owed and for re-checking imported history. Opens on events that had a
 * showrunner; the filter switches to every event, so a night without one
 * still shows what it would have paid. The Payout filter hides (or shows only)
 * nights that fell in the flat voucher tier. Figures are computed live by
 * ShowrunnerPayoutService, never stored -- the same numbers as the payout
 * widget on each event's page.
 */
class ShowrunnerPayouts extends Page implements HasTable
{
    use InteractsWithTable;
    use TranslatesPageLabels;

    protected string $view = 'filament.admin.pages.showrunner-payouts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Showrunner Payouts';

    protected static string|UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 90;

    /** @var array<int, ShowrunnerPayoutResult> */
    protected array $results = [];

    /** @var array<string, int> */
    protected array $creditCentsByDate = [];

    protected ?Collection $tiers = null;

    protected ?Collection $entryPlans = null;

    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Manager)
            && MembershipSetting::current()->showrunner_payouts_enabled;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->exportPayoutsAction(),
        ];
    }

    /**
     * A plain CSV of exactly what the table shows -- same filters and sort
     * (getTableQueryForExport()), every page, not just the visible one --
     * built in one request like the member exports, since there's no queue
     * worker for Filament's own Exporter. Amounts are bare numbers so a
     * spreadsheet can sum them.
     */
    protected function exportPayoutsAction(): Action
    {
        return Action::make('exportPayouts')
            ->label('Export (CSV)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (): StreamedResponse {
                $events = $this->getTableQueryForExport()->get();

                return response()->streamDownload(function () use ($events): void {
                    $handle = fopen('php://output', 'w');
                    // UTF-8 BOM, so Excel shows the breakdown's "×" rather than mojibake.
                    fwrite($handle, "\u{FEFF}");
                    fputcsv($handle, [
                        'Date', 'Event', 'Showrunner', 'Paid at Door', 'Subscribers', 'Subscribers Counted',
                        'Headcount', 'Entry Fee', 'How It Adds Up', 'Payout Type', 'Payout',
                    ]);

                    foreach ($events as $event) {
                        $result = $this->resultFor($event);

                        fputcsv($handle, [
                            $event->event_date->toDateString(),
                            $event->name ?? $event->eventType?->name ?? __('Untitled event'),
                            $event->showrunner ? Member::pickerLabel($event->showrunner) : null,
                            (int) $event->cash_count,
                            (int) $event->sh_count,
                            $result->includeSh ? 'Yes' : 'No',
                            $result->headcount,
                            number_format(Cents::toFloat($result->entryFeeCents), 2, '.', ''),
                            $this->breakdownFor($event),
                            match (true) {
                                $result->payoutAmountCents === null => null,
                                $result->voucherTier !== null => 'Voucher',
                                default => 'Commission',
                            },
                            $result->payoutAmountCents === null ? null : number_format(Cents::toFloat($result->payoutAmountCents), 2, '.', ''),
                        ]);
                    }

                    fclose($handle);
                }, 'showrunner-payouts-'.now()->toDateString().'.csv');
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->payoutsQuery())
            ->defaultSort('event_date', 'desc')
            ->description(fn (): string => __('Total for these events: :amount', [
                'amount' => MembershipSetting::formatMoney(Cents::toFloat($this->filteredTotalCents())),
            ]))
            ->recordUrl(fn (Event $record): string => EventResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('event_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Event')
                    ->state(fn (Event $record): string => $record->name ?? $record->eventType?->name ?? __('Untitled event'))
                    ->searchable(),
                TextColumn::make('showrunner.username')
                    ->label('Showrunner')
                    ->state(fn (Event $record): ?string => $record->showrunner ? Member::pickerLabel($record->showrunner) : null)
                    ->placeholder(__('None')),
                TextColumn::make('cash_count')
                    ->label('Paid at door')
                    ->numeric(),
                TextColumn::make('sh_count')
                    ->label('Subscribers')
                    ->numeric()
                    ->color(fn (Event $record): ?string => $this->resultFor($record)->includeSh ? null : 'gray')
                    ->tooltip(fn (Event $record): string => $this->resultFor($record)->includeSh
                        ? __('Counted: the entry fee is above the subscription credit.')
                        : __("Not counted: the entry fee isn't above the subscription credit.")),
                TextColumn::make('headcount')
                    ->label('Headcount')
                    ->state(fn (Event $record): int => $this->resultFor($record)->headcount)
                    ->numeric(),
                TextColumn::make('entry_fee')
                    ->label('Entry fee')
                    ->money(),
                TextColumn::make('breakdown')
                    ->label('How it adds up')
                    ->state(fn (Event $record): string => $this->breakdownFor($record))
                    ->wrap(),
                TextColumn::make('payout')
                    ->label('Payout')
                    ->state(fn (Event $record): ?float => ($cents = $this->resultFor($record)->payoutAmountCents) === null ? null : Cents::toFloat($cents))
                    ->money()
                    ->placeholder(__('No tiers set'))
                    ->weight(FontWeight::SemiBold),
            ])
            ->filters([
                TernaryFilter::make('has_showrunner')
                    ->label('Showrunner')
                    ->placeholder(__('All events'))
                    ->trueLabel(__('Events with a showrunner'))
                    ->falseLabel(__('Events without one'))
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('showrunner_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('showrunner_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('event_date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('event_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('event_date', '<=', $date))),
                TernaryFilter::make('payout_kind')
                    ->label('Payout')
                    ->placeholder(__('All payouts'))
                    ->trueLabel(__('Commission only'))
                    ->falseLabel(__('Voucher only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereKeyNot($this->voucherEventIds()),
                        false: fn (Builder $query): Builder => $query->whereKey($this->voucherEventIds()),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ]);
    }

    private function payoutsQuery(): Builder
    {
        return Event::query()
            ->with(['showrunner', 'eventType'])
            ->whereDate('event_date', '<=', today())
            ->withCount([
                'attendance as cash_count' => fn (Builder $query) => $query
                    ->whereNotNull('checked_in_at')
                    ->where('entry_covered_by', EntryCoverageSource::None),
                'attendance as sh_count' => fn (Builder $query) => $query
                    ->whereNotNull('checked_in_at')
                    ->whereIn('entry_covered_by', ShowrunnerPayoutService::SUBSCRIPTION_SOURCES),
            ]);
    }

    public function resultFor(Event $event): ShowrunnerPayoutResult
    {
        return $this->results[$event->id] ??= app(ShowrunnerPayoutService::class)->fromCounts(
            $event,
            cashCount: (int) $event->cash_count,
            shCount: (int) $event->sh_count,
            creditCents: $this->creditCentsFor($event),
            tiers: $this->tiers ??= ShowrunnerPayoutTier::orderBy('min_headcount')->get(),
        );
    }

    private function breakdownFor(Event $event): string
    {
        $result = $this->resultFor($event);

        if ($result->voucherTier) {
            return __(':amount voucher', ['amount' => MembershipSetting::formatMoney($result->voucherTier->payout_value)]);
        }

        if ($result->bands === []) {
            return '—';
        }

        return collect($result->bands)
            ->map(fn (ShowrunnerPayoutBand $band): string => __(':count × :fee × :percent%', [
                'count' => $band->attendees,
                'fee' => MembershipSetting::formatMoney(Cents::toFloat($result->entryFeeCents)),
                'percent' => rtrim(rtrim(number_format((float) $band->tier->payout_value, 2), '0'), '.'),
            ]))
            ->implode(' + ');
    }

    /**
     * The tier an event lands in depends on its computed headcount, so this
     * can't be a plain WHERE. Filament hands filters a bare nested builder
     * (no attendance counts), so work the tiers out from the page's own query.
     *
     * @return array<int, int>
     */
    private function voucherEventIds(): array
    {
        return $this->payoutsQuery()->get()
            ->filter(fn (Event $event): bool => $this->resultFor($event)->voucherTier !== null)
            ->modelKeys();
    }

    private function filteredTotalCents(): int
    {
        return $this->getFilteredTableQuery()
            ->get()
            ->sum(fn (Event $event): int => $this->resultFor($event)->payoutAmountCents ?? 0);
    }

    /**
     * Plan::currentFor()'s rule, applied to the Regular plans loaded once, so
     * a long "All events" list doesn't query once per night.
     */
    private function creditCentsFor(Event $event): int
    {
        $this->entryPlans ??= Plan::where('add_on_id', AddOn::entry()->id)
            ->where('duration_months', 1)
            ->orderByDesc('effective_from')
            ->get();

        $date = $event->event_date->toDateString();

        return $this->creditCentsByDate[$date] ??= Cents::of($this->entryPlans->first(
            fn (Plan $plan): bool => $plan->effective_from->toDateString() <= $date
                && ($plan->effective_to === null || $plan->effective_to->toDateString() >= $date),
        )?->credit);
    }
}
