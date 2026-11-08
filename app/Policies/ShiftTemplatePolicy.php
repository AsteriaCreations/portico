<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;

/**
 * Admin+, and only while staff scheduling is on. The tab itself is gated the
 * same way (its relation manager's canViewForRecord()).
 */
class ShiftTemplatePolicy extends RoleGatedPolicy
{
    protected Role $minimumRole = Role::Admin;

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->staff_scheduling_enabled;
    }
}
