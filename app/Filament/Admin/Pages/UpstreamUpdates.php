<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Models\CommandRun;
use App\Models\MembershipSetting;
use App\Services\DeployTrigger;
use App\Services\UpstreamUpdateChecker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Throwable;
use UnitEnum;

/**
 * Visibility into upstream commits pending before running an update
 * (docs/DEPLOYMENT.md §7) -- reads the last upstream:check fetch's local git
 * state, never fetches itself on page load. Admin+, same floor as Technical
 * (infra/ops, away from Manager+ business stats), and additionally gated on
 * upstream_check_enabled since an unconfigured fork has nothing to show here.
 */
class UpstreamUpdates extends Page
{
    protected string $view = 'filament.admin.pages.upstream-updates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected static ?string $navigationLabel = 'Upstream Updates';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Admin)
            && MembershipSetting::current()->upstream_check_enabled;
    }

    public function getPendingCommits(): ?array
    {
        $settings = MembershipSetting::current();

        if (blank($settings->upstream_remote)) {
            return null;
        }

        return app(UpstreamUpdateChecker::class)->pendingCommits($settings->upstream_remote, $settings->upstream_branch);
    }

    public function getLastRun(): ?CommandRun
    {
        return CommandRun::firstWhere('command', 'upstream:check');
    }

    // "Windows accepted the run request" -- not whether the deploy itself
    // succeeded, since scripts/deploy.ps1 runs fully detached from this
    // request. See getLastDeployResult() for that.
    public function getLastDeployTrigger(): ?CommandRun
    {
        return CommandRun::firstWhere('command', 'deploy:trigger');
    }

    public function getLastDeployResult(): ?CommandRun
    {
        return CommandRun::firstWhere('command', 'deploy');
    }

    protected function getHeaderActions(): array
    {
        return [$this->checkForUpdatesAction(), $this->triggerDeployAction()];
    }

    protected function checkForUpdatesAction(): Action
    {
        return Action::make('checkForUpdates')
            ->label('Check for updates now')
            ->action(function (): void {
                // Re-checked here, not just via canAccess() gating the page
                // alone -- same defensive pattern as Technical::runBackupAction().
                abort_unless(auth()->user()->role->atLeast(Role::Admin), 403);

                $exitCode = Artisan::call('upstream:check');

                $exitCode === 0
                    ? Notification::make()->title('Upstream check completed')->success()->send()
                    : Notification::make()->title('Upstream check failed')->body(Artisan::output())->danger()->send();

                $this->redirect(static::getUrl());
            });
    }

    // Deliberately never runs scripts/deploy.ps1 in-process -- that script
    // stops the very web server serving this request, so this only ever asks
    // Windows Task Scheduler to run the already-registered task, which
    // returns almost instantly and is fully decoupled from this request's
    // lifetime. See App\Services\DeployTrigger and docs/DEPLOYMENT.md §7,
    // "Web-triggered updates".
    protected function triggerDeployAction(): Action
    {
        return Action::make('triggerDeploy')
            ->label('Run update now')
            ->color('danger')
            ->visible(fn (): bool => Gate::allows('trigger-deploy'))
            ->requiresConfirmation()
            ->modalDescription('This briefly takes the site offline, pulls the upstream branch, reinstalls dependencies, and runs a database migration. It cannot be undone once started.')
            ->action(function (): void {
                // Re-checked here, not just via ->visible() -- same defensive
                // pattern as checkForUpdatesAction() above, since $data is
                // otherwise a forgeable Livewire property.
                abort_unless(Gate::allows('trigger-deploy'), 403);

                $taskName = MembershipSetting::current()->deploy_task_name;

                try {
                    app(DeployTrigger::class)->trigger($taskName);
                } catch (Throwable $e) {
                    CommandRun::recordFailure('deploy:trigger', $e->getMessage());
                    Notification::make()->title('Failed to trigger the update')->body($e->getMessage())->danger()->send();
                    $this->redirect(static::getUrl());

                    return;
                }

                CommandRun::recordSuccess('deploy:trigger');
                Notification::make()->title('Update triggered')->body('Check back in a few minutes for the result.')->success()->send();

                $this->redirect(static::getUrl());
            });
    }
}
