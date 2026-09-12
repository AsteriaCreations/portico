<?php

namespace App\Console\Commands;

use App\Models\CommandRun;
use App\Models\MembershipSetting;
use App\Services\UpstreamUpdateChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fetches the configured upstream remote so App\Filament\Admin\Pages\
 * UpstreamUpdates can do a fast, local-only diff on every page load. Run on
 * a timer via Windows Task Scheduler, the same way backup:database already
 * is — this app has no Laravel scheduler. A no-op (disabled, or no remote
 * configured) still records a CommandRun success — a deliberate no-op is not
 * a failure, and avoids a misleading "Never run" red stat on
 * ScheduledJobsWidget for a club that has this off.
 */
#[Signature('upstream:check')]
#[Description('Fetch the configured upstream remote so pending-commit visibility stays a local-only check.')]
class CheckUpstream extends Command
{
    public function handle(UpstreamUpdateChecker $checker): int
    {
        $settings = MembershipSetting::current();

        if (! $settings->upstream_check_enabled) {
            $this->info('Upstream check is disabled — nothing to do.');
            CommandRun::recordSuccess('upstream:check');

            return self::SUCCESS;
        }

        if (blank($settings->upstream_remote)) {
            $this->info('No upstream remote configured — nothing to do.');
            CommandRun::recordSuccess('upstream:check');

            return self::SUCCESS;
        }

        try {
            $checker->fetch($settings->upstream_remote);
        } catch (Throwable $e) {
            $this->error("git fetch failed: {$e->getMessage()}");
            CommandRun::recordFailure('upstream:check', $e->getMessage());

            return self::FAILURE;
        }

        $pending = $checker->pendingCommits($settings->upstream_remote, $settings->upstream_branch);
        $count = $pending === null ? 'unknown (branch not resolvable)' : count($pending);

        $this->info("Fetched {$settings->upstream_remote}. Pending commits: {$count}.");
        CommandRun::recordSuccess('upstream:check');

        return self::SUCCESS;
    }
}
