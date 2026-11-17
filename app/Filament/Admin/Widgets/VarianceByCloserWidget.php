<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Filament\Concerns\ReadsAnalyticsPeriod;
use App\Models\MembershipSetting;
use App\Services\Analytics\StaffAnalytics;
use App\Support\Cents;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Register shifts closed in the Analytics page's period, by who closed them
 * (StaffAnalytics::varianceByCloser()), so a pattern of short drawers shows
 * up without paging through shift history.
 */
class VarianceByCloserWidget extends StatsOverviewWidget
{
    use ReadsAnalyticsPeriod;

    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    protected function getHeading(): ?string
    {
        return __('Register variance by who closed — :period', ['period' => $this->period()->label()]);
    }

    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->register_shifts_enabled;
    }

    protected function getStats(): array
    {
        $closers = app(StaffAnalytics::class)->varianceByCloser($this->period());

        if ($closers->isEmpty()) {
            return [Stat::make(__('Shifts closed'), 0)];
        }

        return $closers
            ->map(fn (array $closer) => Stat::make(
                $closer['name'],
                MembershipSetting::formatMoney(Cents::toFloat($closer['variance_cents'])),
            )
                // Said in words too, not by colour alone.
                ->description(trans_choice(
                    ':off of :count shift off balance|:off of :count shifts off balance',
                    $closer['shifts'],
                    ['off' => $closer['off']],
                ))
                ->color(match (true) {
                    $closer['variance_cents'] === 0 => 'success',
                    $closer['variance_cents'] > 0 => 'warning',
                    default => 'danger',
                }))
            ->all();
    }
}
