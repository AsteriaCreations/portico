<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MiscellaneousPaymentPolicy extends RoleGatedPolicy
{
    // Door+ — same floor as RegisterShiftPolicy: whoever can open a shift
    // and record a drop can also record and view this ledger.
    protected Role $minimumRole = Role::Door;

    // Append-only, mirrors RegisterDrop/Voucher/BanException — a correction
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
