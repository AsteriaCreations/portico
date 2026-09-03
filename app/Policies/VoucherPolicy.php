<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class VoucherPolicy extends RoleGatedPolicy
{
    // view/viewAny: Manager+ can browse the ledger.
    protected Role $minimumRole = Role::Manager;

    // Also hides the resource entirely (nav item + direct page access, via
    // Resource::canAccess() -> canViewAny()) when the club has vouchers
    // turned off -- see App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->vouchers_enabled;
    }

    // Issuing a row (including a correction) is Admin+ only.
    public function create(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    // Append-only ledger: no edit or delete path for anyone, ever. A
    // correction is a new offsetting row, not a change to an existing one.
    // See docs/BLUEPRINT.md "Vouchers".
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
