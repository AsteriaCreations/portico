<?php

namespace App\Policies;

use App\Models\MembershipSetting;
use App\Models\User;

class AddOnPolicy extends RoleGatedPolicy
{
    // Also hides the resource entirely (nav item + direct page access, via
    // Resource::canAccess() -> canViewAny()) when the club has add-ons
    // turned off -- see App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->add_ons_enabled;
    }
}
