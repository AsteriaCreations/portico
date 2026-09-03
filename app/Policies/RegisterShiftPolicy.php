<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RegisterShiftPolicy extends RoleGatedPolicy
{
    // view/viewAny: Door+ — whoever can open a shift can also see shift
    // history and variance, not just Manager+ (see the commit history).
    protected Role $minimumRole = Role::Door;

    // Also hides the resource entirely (nav item + direct page access, via
    // Resource::canAccess() -> canViewAny()) when the club has register-shift
    // tracking turned off -- see App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->register_shifts_enabled;
    }

    // A shift is opened/closed only through CheckIn's page actions via
    // RegisterShiftService, never a generic Filament form — no create/edit
    // path for anyone.
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
