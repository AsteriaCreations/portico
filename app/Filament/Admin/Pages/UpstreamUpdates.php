<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Models\CommandRun;
use App\Models\MembershipSetting;
use App\Services\UpstreamUpdateChecker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
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

    protected function getHeaderActions(): array
    {
        return [$this->checkForUpdatesAction()];
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
}
