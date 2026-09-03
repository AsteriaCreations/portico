<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AttendanceBehaviorNotePolicy extends RoleGatedPolicy
{
    // view/viewAny: Manager+ can review the full audit trail (see
    // BehaviorNotesRelationManager) -- deliberately not flag-gated by
    // behavior_notes_enabled, matching every other audit-trail relation
    // manager in this app: a flag stops new writes, never hides data
    // already collected. Volunteer+ writes and partial reads happen on
    // ActivePatrons instead, gated by manage-visit-notes/
    // manage-behavior-notes, not this policy.
    protected Role $minimumRole = Role::Manager;

    // Append-only: no edit or delete path for anyone, ever. Rows are
    // written only by ActivePatrons::addBehaviorNoteAction(), never through
    // a policy-authorized Filament form.
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
