<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Services\Analytics\AttendanceAnalytics;
use App\Support\Cents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tonight's numbers. Always tonight: it doesn't follow the Analytics page's
 * period.
 */
class TonightOverviewWidget extends StatsOverviewWidget
{
    // Not lazy: hosted on the Analytics page, where a Manager should see it
    // the instant the page loads, not after it scrolls into view — also
    // matters for testing, since a lazy widget's real content never appears
    // in a server-rendered HTTP test response at all (see RecordDeparturesWidget).
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Manager) ?? false;
    }

    protected function getStats(): array
    {
        $tonight = app(AttendanceAnalytics::class)->tonight();

        return [
            Stat::make(__("Tonight's check-ins"), $tonight['check_ins']),
            Stat::make(__("Tonight's door take"), MembershipSetting::formatMoney(Cents::toFloat($tonight['door_take_cents']))),
        ];
    }
}
