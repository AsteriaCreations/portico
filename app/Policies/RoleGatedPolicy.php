<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class RoleGatedPolicy
{
    protected Role $minimumRole = Role::Manager;

    public function viewAny(User $user): bool
    {
        return $user->role->atLeast($this->minimumRole);
    }

    public function view(User $user, Model $model): bool
    {
        return $user->role->atLeast($this->minimumRole);
    }

    public function create(User $user): bool
    {
        return $user->role->atLeast($this->minimumRole);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->role->atLeast($this->minimumRole);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->role->atLeast($this->minimumRole);
    }

    public function deleteAny(User $user): bool
    {
        return $user->role->atLeast($this->minimumRole);
    }
}
