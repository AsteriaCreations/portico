<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PrivateRentalPaymentPolicy extends RoleGatedPolicy
{
    // Door+, like the desk that takes the payment.
    protected Role $minimumRole = Role::Door;

    // Append-only: a refund is a new negative row, never an edit.
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
