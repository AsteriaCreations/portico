<?php

namespace App\Policies;

use App\Models\MembershipSetting;
use App\Models\User;

/**
 * Manager+ (who keeps the task lists), hidden while staff scheduling is
 * off. Deleting an area leaves its tasks in place, with no area.
 */
class TaskAreaPolicy extends RoleGatedPolicy
{
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->staff_scheduling_enabled;
    }
}
