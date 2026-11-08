<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin+ (who builds the schedule), and hidden entirely while staff
 * scheduling is off (Resource::canAccess() -> canViewAny()).
 */
class ShiftRolePolicy extends RoleGatedPolicy
{
    protected Role $minimumRole = Role::Admin;

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->staff_scheduling_enabled;
    }

    // A role with shifts on events keeps their history: turn it off
    // (active) instead. The bulk delete checks each record against this too.
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && ! $model->slots()->exists();
    }
}
