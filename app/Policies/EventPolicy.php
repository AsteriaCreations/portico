<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EventPolicy extends RoleGatedPolicy
{
    public function create(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    // Event cost is fixed and Admin+-only to change — Manager has no
    // business adjusting an event's price. See
    // docs/BLUEPRINT.md "Prepay events".
    public function update(User $user, Model $model): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function deleteAny(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }
}
