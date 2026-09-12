<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\CommandRun;
use App\Models\MembershipSetting;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Surfaces whether the two Windows-Task-Scheduler-driven commands
 * (backup:database, vouchers:grant-comp-rewards) are still actually running —
 * this app has no Laravel scheduler, so a silently broken scheduled task
 * would otherwise go unnoticed until a restore is needed or vouchers are
 * conspicuously missing.
 */
class ScheduledJobsWidget extends StatsOverviewWidget
{
    // Not lazy: hosted on the Technical page (not the Dashboard) precisely so
    // an Admin sees this the instant the page loads — also matters for
    // testing, since a lazy widget's real content never appears in a
    // server-rendered HTTP test response at all (see RecordDeparturesWidget).
    protected static bool $isLazy = false;

    // Both commands are meant to run nightly — this gives a couple of
    // hours' buffer past a missed midnight run before flagging red.
    private const STALE_AFTER_HOURS = 26;

    public static function canView(): bool
    {
        return auth()->user()?->role->atLeast(Role::Admin) ?? false;
    }

    protected function getStats(): array
    {
        $stats = [
            $this->statFor('backup:database', 'Database backup'),
            $this->statFor('vouchers:grant-comp-rewards', 'Comp reward vouchers'),
        ];

        if (MembershipSetting::current()->upstream_check_enabled) {
            $stats[] = $this->statFor('upstream:check', 'Upstream check');
        }

        return $stats;
    }

    private function statFor(string $command, string $label): Stat
    {
        $run = CommandRun::firstWhere('command', $command);

        if (! $run?->last_success_at) {
            return Stat::make($label, 'Never run')->color('danger');
        }

        $isFailing = $run->last_failure_at?->gt($run->last_success_at) ?? false;
        $isStale = $run->last_success_at->lt(now()->subHours(self::STALE_AFTER_HOURS));

        return Stat::make($label, $run->last_success_at->diffForHumans())
            ->description($isFailing ? "Failing since {$run->last_failure_at->diffForHumans()}" : null)
            ->color($isFailing || $isStale ? 'danger' : 'success');
    }
}
