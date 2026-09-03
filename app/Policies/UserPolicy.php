<?php

namespace App\Policies;

use App\Enums\Role;

class UserPolicy extends RoleGatedPolicy
{
    protected Role $minimumRole = Role::Admin;
}
