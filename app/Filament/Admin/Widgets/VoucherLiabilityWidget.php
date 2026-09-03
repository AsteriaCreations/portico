<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\Voucher;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Total outstanding account-credit liability across every member — the sum
 * of every Voucher.amount row (positive = issued, negative = redeemed/
 * corrected, per Member::voucherBalance()'s own convention), not scoped to
 * any date window since a voucher balance carries forward indefinitely.
 */
class VoucherLiabilityWidget extends StatsOverviewWidget
{
    // See TonightOverviewWidget's own comment on why this is set — same
    // Analytics-page visibility and testability reasoning.
    protected static bool $isLazy = false;

    // Also hidden when the club has vouchers turned off entirely — no point
    // surfacing a liability figure for a feature that's not in use.
    public static function canView(): bool
    {
        return (auth()->user()?->role->atLeast(Role::Manager) ?? false)
            && MembershipSetting::current()->vouchers_enabled;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Outstanding voucher liability', '$'.number_format(Voucher::sum('amount'), 2)),
        ];
    }
}
