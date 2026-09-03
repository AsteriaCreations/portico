<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\Attendance;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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
        $baseQuery = fn () => Attendance::query()
            ->whereHas('event', fn ($query) => $query->whereDate('event_date', today()))
            ->whereNotNull('checked_in_at');

        return [
            Stat::make("Tonight's check-ins", $baseQuery()->count()),
            Stat::make("Tonight's door take", '$'.number_format($baseQuery()->sum('amount_paid'), 2)),
        ];
    }
}
