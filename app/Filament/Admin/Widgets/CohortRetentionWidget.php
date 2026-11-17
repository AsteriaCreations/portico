<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Services\Analytics\RetentionAnalytics;
use Filament\Widgets\Widget;

/**
 * The cohort grid: one row per first-visit month, one column per month
 * after it, each cell the share of that month's newcomers who visited
 * (RetentionAnalytics::cohorts()). The twelve months end with the Analytics
 * page's period.
 */
class CohortRetentionWidget extends Widget
{
    use ReadsAnalyticsPeriod;

    private const MONTHS_AFTER = 6;

    protected string $view = 'filament.admin.widgets.cohort-retention-widget';

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'cohorts' => app(RetentionAnalytics::class)->cohorts($this->period(), self::MONTHS_AFTER),
            'monthsAfter' => self::MONTHS_AFTER,
        ];
    }
}
