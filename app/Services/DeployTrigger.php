<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * Asks Windows Task Scheduler to run the configured on-demand deploy task
 * right now -- see App\Filament\Admin\Pages\UpstreamUpdates's
 * triggerDeployAction(). Deliberately never runs scripts/deploy.ps1 itself:
 * that script stops the very web server serving the request that would
 * trigger it, so the only safe hand-off is to a task that's already
 * registered and runs fully detached from this PHP process (docs/DEPLOYMENT.md
 * §7, "Web-triggered updates"). trigger() only reports whether Windows
 * accepted the run request, not whether the deploy itself succeeds -- see
 * App\Console\Commands\RecordDeployResult for that.
 */
class DeployTrigger
{
    public function trigger(string $taskName): void
    {
        $this->assertSafeTaskName($taskName);

        $result = Process::timeout(15)->run(['schtasks', '/run', '/TN', $taskName]);

        if ($result->failed()) {
            throw new RuntimeException("schtasks /run failed: {$result->errorOutput()}");
        }
    }

    // A task name starting with '-' or '/' could otherwise be parsed by
    // schtasks as another flag -- the same argument-injection class of bug
    // UpstreamUpdateChecker::SAFE_REF_PATTERN guards against for git.
    // Process's array-form command already avoids shell-string injection;
    // this is a second, independent defense against schtasks's own argument
    // parsing. Unlike a git ref, a task name can legitimately contain spaces
    // (see the "IX Membership - Deploy Update" naming convention in
    // docs/DEPLOYMENT_RUNBOOK.md), so this only rejects a dangerous leading
    // character and embedded newlines, not the permissive git-ref pattern.
    private function assertSafeTaskName(string $name): void
    {
        if (! preg_match('/^[^\s\/-][^\r\n]*$/', $name)) {
            throw new InvalidArgumentException("Not a safe scheduled task name: {$name}");
        }
    }
}
