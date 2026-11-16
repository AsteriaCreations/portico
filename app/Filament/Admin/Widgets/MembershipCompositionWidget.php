<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Admin\Resources\Members\MemberResource;
use App\Services\Analytics\MembershipAnalytics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Active-member composition by category as it stands today -- the roster
 * itself, not visits (CategoryBreakdownWidget's own concern), so it doesn't
 * follow the Analytics page's period.
 */
class MembershipCompositionWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Active Membership — By Category');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        return app(MembershipAnalytics::class)->activeByCategory()
            ->map(fn (array $row) => Stat::make($row['name'], trans_choice(':count member|:count members', $row['members']))
                ->url(MemberResource::getUrl('index', ['tableFilters' => [
                    'category_id' => ['value' => $row['id']],
                    'is_active' => ['value' => 1],
                ]])))
            ->all();
    }
}
