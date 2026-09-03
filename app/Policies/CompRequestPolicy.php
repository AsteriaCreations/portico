<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CompRequestPolicy extends RoleGatedPolicy
{
    // Manager+ can see the queue (inherited Manager minimum) -- also hides
    // CompRequestsRelationManager's tab on EventResource when the club has
    // this feature turned off, see App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->showrunner_comp_requests_enabled;
    }

    // Approving or rejecting a request is literally an update to it, and is
    // Admin+ only — the club's explicit "admin+ has to approve" requirement.
    public function update(User $user, Model $model): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    // Append-only decision trail, same as Vouchers/BanExceptions/
    // MemberStatusChanges: a request is approved or rejected, never deleted.
    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
