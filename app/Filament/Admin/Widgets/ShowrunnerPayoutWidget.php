<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\Event;
use App\Models\MembershipSetting;
use App\Services\ShowrunnerPayoutResult;
use App\Services\ShowrunnerPayoutService;
use Filament\Widgets\Widget;

/**
 * Read-only commission breakdown for the event's assigned showrunner —
 * reporting/liability only, computed live via ShowrunnerPayoutService, never
 * stored. Renders nothing when the event has no showrunner assigned.
 */
class ShowrunnerPayoutWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.showrunner-payout-widget';

    // Not lazy: a Manager opening this event's edit page needs the figure
    // immediately, and it must appear in a server-rendered test response.
    protected static bool $isLazy = false;

    public ?Event $record = null;

    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->showrunner_payouts_enabled;
    }

    public function getResult(): ?ShowrunnerPayoutResult
    {
        if ($this->record?->showrunner_id === null) {
            return null;
        }

        return app(ShowrunnerPayoutService::class)->calculate($this->record);
    }

    public function getSettings(): MembershipSetting
    {
        return MembershipSetting::current();
    }
}
