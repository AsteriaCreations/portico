<?php

namespace App\Policies;

use App\Models\MembershipSetting;
use App\Models\User;

class ShowrunnerPayoutTierPolicy extends RoleGatedPolicy
{
    // Also hides the resource entirely (nav item + direct page access, via
    // Resource::canAccess() -> canViewAny()) when the club has showrunner
    // payouts turned off -- see App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->showrunner_payouts_enabled;
    }
}
