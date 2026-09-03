<?php

namespace App\Policies;

use App\Enums\Role;

class UserCapabilityPolicy extends RoleGatedPolicy
{
    // Matches UserPolicy's own floor -- granting/revoking a capability is
    // part of managing user accounts.
    protected Role $minimumRole = Role::Admin;
}
