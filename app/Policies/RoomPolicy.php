<?php

namespace App\Policies;

use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RoomPolicy extends RoleGatedPolicy
{
    // Also hides the resource entirely (nav item + direct page access, via
    // Resource::canAccess() -> canViewAny()) while private rentals are off
    // -- see App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->private_rentals_enabled;
    }

    // A room that's been booked keeps its bookings' history: turn it off
    // (active) instead. The bulk delete checks each record against this too
    // (RoomsTable's authorizeIndividualRecords).
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model) && ! $model->privateRentals()->exists();
    }
}
