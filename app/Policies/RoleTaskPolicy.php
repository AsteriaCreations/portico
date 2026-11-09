<?php

namespace App\Policies;

use App\Models\MembershipSetting;
use App\Models\User;

/**
 * Manager+ keeps each role's standard task list, hidden while staff
 * scheduling is off.
 */
class RoleTaskPolicy extends RoleGatedPolicy
{
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->staff_scheduling_enabled;
    }
}
