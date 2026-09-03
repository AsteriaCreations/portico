<?php

namespace App\Filament\Admin\Pages;

use App\Enums\Role;
use App\Filament\Admin\Widgets\ScheduledJobsWidget;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Support\Facades\Artisan;
use UnitEnum;

/**
 * Admin+ infra/ops concerns, split off the Dashboard and away from Manager+
 * business stats — scheduled-command health (backup:database,
 * vouchers:grant-comp-rewards) and Filament's own version info.
 */
class Technical extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $navigationLabel = 'Technical';

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    public static function canAccess(): bool
    {
        return auth()->user()->role->atLeast(Role::Admin);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema(fn (): array => $this->getWidgetsSchemaComponents([
                ScheduledJobsWidget::class,
                FilamentInfoWidget::class,
            ])),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->runBackupAction(),
            $this->runVoucherGrantAction(),
        ];
    }

    /**
     * Lets an Admin run a scheduled command on demand — e.g. right after
     * fixing whatever broke it, rather than waiting for the next Windows
     * Task Scheduler tick. Both commands already call
     * CommandRun::recordSuccess()/recordFailure() internally, so a manual
     * run is indistinguishable from a scheduled one to ScheduledJobsWidget.
     * Redirects back to this page afterward (rather than trying to force a
     * live refresh of the nested widget) so the widget re-queries fresh data.
     */
    protected function runBackupAction(): Action
    {
        return Action::make('runBackupDatabase')
            ->label('Run backup now')
            ->action(function (): void {
                // Re-checked here, not just via canAccess() gating the page
                // alone — same defensive pattern as ActivePatrons::departAction().
                abort_unless(auth()->user()->role->atLeast(Role::Admin), 403);

                $exitCode = Artisan::call('backup:database');

                $exitCode === 0
                    ? Notification::make()->title('Backup completed')->success()->send()
                    : Notification::make()->title('Backup failed')->body(Artisan::output())->danger()->send();

                $this->redirect(static::getUrl());
            });
    }

    protected function runVoucherGrantAction(): Action
    {
        return Action::make('runVoucherGrant')
            ->label('Run comp-reward grant now')
            ->action(function (): void {
                abort_unless(auth()->user()->role->atLeast(Role::Admin), 403);

                $exitCode = Artisan::call('vouchers:grant-comp-rewards');

                $exitCode === 0
                    ? Notification::make()->title('Comp-reward grant completed')->success()->send()
                    : Notification::make()->title('Comp-reward grant failed')->body(Artisan::output())->danger()->send();

                $this->redirect(static::getUrl());
            });
    }
}
