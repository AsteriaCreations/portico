<?php

namespace App\Policies;

use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

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

    // A task someone has done keeps its record: turn it off (active)
    // instead. The bulk delete checks each record against this too.
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && ! $model->completions()->exists();
    }
}
