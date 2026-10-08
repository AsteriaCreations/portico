<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\Event;
use App\Models\InstructorPayout;
use App\Models\MembershipSetting;
use App\Services\InstructorPayoutResult;
use App\Services\InstructorPayoutService;
use Filament\Widgets\Widget;

/**
 * Read-only per-head instructor payout breakdown for this event, computed
 * live via InstructorPayoutService, never stored. Renders nothing when the
 * event's type has no configured InstructorPayRate rows.
 */
class InstructorPayoutWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.instructor-payout-widget';

    // Not lazy: see ShowrunnerPayoutWidget's own comment on why.
    protected static bool $isLazy = false;

    public ?Event $record = null;

    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->instructor_payouts_enabled;
    }

    public function getResult(): ?InstructorPayoutResult
    {
        if ($this->record === null) {
            return null;
        }

        $result = app(InstructorPayoutService::class)->calculate($this->record);

        return $result->lineItems === [] ? null : $result;
    }

    /**
     * What was actually handed over at the desk, oldest first, each row
     * labelled payment or correction -- see InstructorPayoutService::history().
     *
     * @return list<array{payout: InstructorPayout, kind: 'payment'|'correction'}>
     */
    public function getHistory(): array
    {
        return $this->record ? app(InstructorPayoutService::class)->history($this->record) : [];
    }
}
