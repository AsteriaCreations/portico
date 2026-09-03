<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BanExceptionPolicy extends RoleGatedPolicy
{
    // view/viewAny/create: Manager+ can grant a one-time exception.
    protected Role $minimumRole = Role::Manager;

    // A granted exception is a standing decision, not editable after the
    // fact — mirrors the audit-log-style append-only ledgers elsewhere
    // (Vouchers, MemberStatusChange). Granting a new one is how you change
    // course; there's no edit path.
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
