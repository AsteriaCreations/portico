<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MembershipSetting;
use App\Models\PrivateRental;
use App\Models\User;

/**
 * The Private Rentals list is Door+ while private rentals are on. Bookings
 * are only ever written through PrivateRentalService (the desk's Book, and
 * Change / Cancel on the desk or the list), never a plain form.
 */
class PrivateRentalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(Role::Door) && MembershipSetting::current()->private_rentals_enabled;
    }

    public function view(User $user, PrivateRental $rental): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PrivateRental $rental): bool
    {
        return false;
    }

    public function delete(User $user, PrivateRental $rental): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
