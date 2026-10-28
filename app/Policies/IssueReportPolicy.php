<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class IssueReportPolicy extends RoleGatedPolicy
{
    // view/viewAny: Manager+ can read and copy the snapshots.
    protected Role $minimumRole = Role::Manager;

    // Append-only: no edit or delete path for anyone, ever. Rows are written
    // only by IssueReportService (the desk's "Report a problem"), never
    // through a form.
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
