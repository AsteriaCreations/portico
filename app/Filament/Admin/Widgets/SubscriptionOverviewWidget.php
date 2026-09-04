<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\AddOn;
use App\Models\MembershipSetting;
use App\Models\Subscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Subscriptions are a core recurring revenue stream with no prior Analytics
 * visibility at all. "Active this month" mirrors Member::
 * hasActiveSubscriptionFor()'s own predicate (covered_month is stored as a
 * first-of-month date, per SubscriptionBundleService::resolveStart());
 * revenue windows on paid_on (when money actually changed hands), not
 * covered_month, matching how cash reporting elsewhere windows on
 * transaction date rather than covered period.
 */
class SubscriptionOverviewWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $thisMonth = now()->startOfMonth()->toDateString();

        $stats = [
            Stat::make(
                'Active Regular subscribers',
                Subscription::where('add_on_id', AddOn::entry()->id)
                    ->whereDate('covered_month', $thisMonth)
                    ->distinct('member_id')
                    ->count('member_id'),
            ),
        ];

        // Pool subscriptions aren't retroactively hidden once purchased (see
        // AddOn::priceFor()'s own comment) -- but a club that's turned pool
        // off entirely doesn't need this count cluttering the page.
        $poolAddOn = AddOn::pool();
        if ($poolAddOn && MembershipSetting::current()->pool_enabled) {
            $stats[] = Stat::make(
                'Active Pool subscribers',
                Subscription::where('add_on_id', $poolAddOn->id)
                    ->whereDate('covered_month', $thisMonth)
                    ->distinct('member_id')
                    ->count('member_id'),
            );
        }

        $stats[] = Stat::make(
            'Subscription revenue this week',
            '$'.number_format(
                Subscription::whereBetween('paid_on', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->sum('amount_paid'),
                2,
            ),
        );

        return $stats;
    }
}
