<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PoolDayPassPolicy extends RoleGatedPolicy
{
    // view/viewAny: Manager+ can browse purchased passes.
    protected Role $minimumRole = Role::Manager;

    // Also hides PoolDayPassesRelationManager's tab on EventResource (nav +
    // direct access, via Resource::canAccess() -> canViewAny()) when pool
    // is turned off -- see App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->pool_enabled;
    }

    // Written only through CheckIn::purchasePoolDayPassAction() -- a
    // one-time purchase, never edited or deleted after the fact, same
    // append-only convention as Vouchers/BanExceptions/MemberStatusChange.
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
