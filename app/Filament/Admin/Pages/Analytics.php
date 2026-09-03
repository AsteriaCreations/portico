<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Filament\Admin\Widgets\AddOnRevenueWidget;
use App\Filament\Admin\Widgets\CompCostWidget;
use App\Filament\Admin\Widgets\MembershipCompositionWidget;
use App\Filament\Admin\Widgets\MonthlyRevenueChartWidget;
use App\Filament\Admin\Widgets\NewMembersChartWidget;
use App\Filament\Admin\Widgets\RegisterVarianceWidget;
use App\Filament\Admin\Widgets\SubscriptionOverviewWidget;
use App\Filament\Admin\Widgets\TonightOverviewWidget;
use App\Filament\Admin\Widgets\VoucherLiabilityWidget;
use App\Filament\Admin\Widgets\WeeklyAttendanceChartWidget;
use App\Filament\Admin\Widgets\WeeklyCategoryBreakdownWidget;
use App\Filament\Admin\Widgets\WeeklySummaryWidget;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Manager+ business-reporting stats, split off the Dashboard so it stays
 * operational-only — tonight's numbers, the per-event-type weekly summary,
 * and its sibling per-member-category weekly summary, grouped together
 * rather than sitting beside "what's happening right now" content.
 */
class Analytics extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Analytics';

    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Manager);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                TonightOverviewWidget::class,
                WeeklySummaryWidget::class,
                VoucherLiabilityWidget::class,
                SubscriptionOverviewWidget::class,
                RegisterVarianceWidget::class,
            ])),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                WeeklyCategoryBreakdownWidget::class,
            ])),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                AddOnRevenueWidget::class,
            ])),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                CompCostWidget::class,
            ])),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                MembershipCompositionWidget::class,
            ])),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                WeeklyAttendanceChartWidget::class,
            ])),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                MonthlyRevenueChartWidget::class,
            ])),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                NewMembersChartWidget::class,
            ])),
        ]);
    }
}
