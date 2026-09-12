<?php

namespace App\Console\Commands;

use App\Models\CommandRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * A thin CommandRun writer that scripts/deploy.ps1 shells out to at the end
 * of a run -- a .ps1 can't call CommandRun directly, so this is the same
 * "wrapper calls php artisan" hand-off the scripts/run-*.bat files already
 * use for backup:database/upstream:check. Records under the 'deploy' key,
 * distinct from 'deploy:trigger' (recorded by App\Services\DeployTrigger's
 * caller, which only confirms Windows accepted the run request) -- this key
 * is the actual outcome of the pull/composer/npm/migrate/cache sequence.
 */
#[Signature('deploy:record-result {--failed} {--message=}')]
#[Description('Records the outcome of a scripts/deploy.ps1 run so App\Filament\Admin\Pages\UpstreamUpdates can show it.')]
class RecordDeployResult extends Command
{
    public function handle(): int
    {
        if ($this->option('failed')) {
            CommandRun::recordFailure('deploy', (string) $this->option('message'));

            return self::FAILURE;
        }

        CommandRun::recordSuccess('deploy');

        return self::SUCCESS;
    }
}
