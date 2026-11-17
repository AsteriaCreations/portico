<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Admin\Pages\ShiftsWorked;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Services\Analytics\StaffAnalytics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Staff shifts and task lists in the Analytics page's period
 * (StaffAnalytics::staffActivity()). Hidden when staff scheduling is off.
 */
class StaffActivityWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Staff shifts and tasks — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->staff_scheduling_enabled;
    }

    protected function getStats(): array
    {
        $staff = app(StaffAnalytics::class)->staffActivity($this->period());

        $worked = Stat::make(__('Shifts worked'), __(':worked of :scheduled', ['worked' => $staff['worked'], 'scheduled' => $staff['scheduled']]))
            ->description(trans_choice(':count person worked a shift|:count people worked a shift', $staff['people']));

        if (ShiftsWorked::canAccess()) {
            $worked->url(ShiftsWorked::getUrl());
        }

        return [
            $worked,
            Stat::make(__('Tasks ticked'), $staff['ticks'])
                ->description(__(':count ticked off shift', ['count' => $staff['off_shift_ticks']])),
        ];
    }
}
