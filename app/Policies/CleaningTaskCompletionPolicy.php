<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CleaningTaskCompletionPolicy extends RoleGatedPolicy
{
    // view/viewAny: Manager+ can see the completion log.
    protected Role $minimumRole = Role::Manager;

    // Append-only audit trail: rows are written only by
    // CleaningChecklist's complete action (any capability holder, any
    // role), never through an admin form, and never edited or deleted.
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
