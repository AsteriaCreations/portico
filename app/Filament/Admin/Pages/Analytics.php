<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Filament\Admin\Widgets\AddOnRevenueWidget;
use App\Filament\Admin\Widgets\AttendanceTrendChartWidget;
use App\Filament\Admin\Widgets\CategoryBreakdownWidget;
use App\Filament\Admin\Widgets\CompCostWidget;
use App\Filament\Admin\Widgets\MembershipCompositionWidget;
use App\Filament\Admin\Widgets\NewMembersChartWidget;
use App\Filament\Admin\Widgets\RegisterVarianceWidget;
use App\Filament\Admin\Widgets\RevenueByEventTypeWidget;
use App\Filament\Admin\Widgets\RevenueTrendChartWidget;
use App\Filament\Admin\Widgets\SubscriptionOverviewWidget;
use App\Filament\Admin\Widgets\TonightOverviewWidget;
use App\Filament\Admin\Widgets\VoucherLiabilityWidget;
use App\Filament\Concerns\TranslatesPageLabels;
use App\Support\AnalyticsPeriod;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Livewire\Attributes\Url;

/**
 * Manager+ business reporting, split off the Dashboard so that stays
 * operational-only. One period, chosen at the top, applies to every widget
 * that is about a span of time (App\Support\AnalyticsPeriod); the widgets
 * are grouped into tabs, and only the open tab's widgets are rendered, so a
 * tab nobody is looking at runs no queries.
 */
class Analytics extends Page
{
    use HasFiltersForm;
    use TranslatesPageLabels;

    private const DEFAULT_TAB = 'overview';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Analytics';

    // Ungrouped, pinned near the top alongside Dashboard and the Check-In
    // Desk rather than buried in a group -- it's a landing page, not a
    // record type or a setting.
    protected static ?int $navigationSort = 0;

    #[Url]
    public string $tab = self::DEFAULT_TAB;

    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Manager);
    }

    public function mount(): void
    {
        $this->updatedTab();
    }

    /**
     * The tab also arrives from the URL: an unknown one would render no tab
     * at all.
     */
    public function updatedTab(): void
    {
        if (! array_key_exists($this->tab, $this->tabWidgets())) {
            $this->tab = self::DEFAULT_TAB;
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        $isCustom = fn (Get $get): bool => $get('preset') === AnalyticsPeriod::CUSTOM;

        return $schema->components([
            Select::make('preset')
                ->label('Period')
                ->options(fn (): array => AnalyticsPeriod::presets())
                ->default(AnalyticsPeriod::DEFAULT_PRESET)
                ->selectablePlaceholder(false),
            DatePicker::make('from')
                ->label('From')
                ->visible($isCustom),
            DatePicker::make('until')
                ->label('Until')
                ->visible($isCustom),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $labels = [
            'overview' => __('Overview'),
            'revenue' => __('Revenue'),
            'members' => __('Members'),
        ];

        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Tabs::make(__('Analytics sections'))
                ->livewireProperty('tab')
                ->tabs(collect($this->tabWidgets())
                    ->map(fn (array $widgets, string $tab): Tab => Tab::make($labels[$tab])->schema(fn (): array => [
                        Grid::make(2)->schema(fn (): array => $this->getWidgetsSchemaComponents($widgets)),
                    ]))
                    ->all()),
        ]);
    }

    /**
     * @return array<string, list<class-string<Widget>>> tab key => its widgets, in order
     */
    private function tabWidgets(): array
    {
        return [
            'overview' => [
                TonightOverviewWidget::class,
                AttendanceTrendChartWidget::class,
                RevenueTrendChartWidget::class,
            ],
            'revenue' => [
                RevenueByEventTypeWidget::class,
                SubscriptionOverviewWidget::class,
                AddOnRevenueWidget::class,
                CompCostWidget::class,
                RegisterVarianceWidget::class,
                VoucherLiabilityWidget::class,
            ],
            'members' => [
                MembershipCompositionWidget::class,
                CategoryBreakdownWidget::class,
                NewMembersChartWidget::class,
            ],
        ];
    }
}
