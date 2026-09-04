<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AddOnDayPassPolicy extends RoleGatedPolicy
{
    // view/viewAny: Manager+ can browse purchased passes.
    protected Role $minimumRole = Role::Manager;

    // Also hides AddOnDayPassesRelationManager's tab on EventResource (nav +
    // direct access, via Resource::canAccess() -> canViewAny()) when pool
    // is turned off -- see App\Filament\Admin\Pages\FeatureFlags. Gated on
    // pool_enabled specifically rather than generically, since Pool remains
    // the only day-passable add-on; a club adding a second one would need
    // this widened.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->pool_enabled;
    }

    // Written only through CheckIn::purchaseAddOnDayPassAction() -- a
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
