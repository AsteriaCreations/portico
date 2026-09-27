<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class VisitRemovalPolicy extends RoleGatedPolicy
{
    // view/viewAny: Manager+ can see the removals log.
    protected Role $minimumRole = Role::Manager;

    // Append-only: no edit or delete path for anyone, ever. Rows are written
    // only by VisitRemovalService, never through a form.
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
