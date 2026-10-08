<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class InstructorPayoutPolicy extends RoleGatedPolicy
{
    // Door+ — same floor as MiscellaneousPaymentPolicy: whoever works the
    // cash box can pay the instructor out of it.
    protected Role $minimumRole = Role::Door;

    // Append-only, mirrors RegisterDrop/MiscellaneousPayment — a correction
    // is a new offsetting row, never an edit.
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
