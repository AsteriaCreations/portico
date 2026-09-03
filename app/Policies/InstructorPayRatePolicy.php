<?php

namespace App\Policies;

use App\Models\MembershipSetting;
use App\Models\User;

class InstructorPayRatePolicy extends RoleGatedPolicy
{
    // Also hides InstructorPayRatesRelationManager's tab on EventTypeResource
    // (nav + direct access, via Resource::canAccess() -> canViewAny()) when
    // instructor payouts are turned off -- see
    // App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->instructor_payouts_enabled;
    }
}
