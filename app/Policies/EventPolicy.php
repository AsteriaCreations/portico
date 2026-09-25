<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class EventPolicy extends RoleGatedPolicy
{
    public function create(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    // Event cost is fixed and Admin+-only to change — Manager has no
    // business adjusting an event's price. See
    // docs/BLUEPRINT.md "Prepay events".
    public function update(User $user, Model $model): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    // Only an event nothing points at can be deleted -- attendance, comp
    // requests, day passes and ban exceptions are money and audit history,
    // and their foreign keys would refuse the delete anyway. Anything else
    // is archived instead (see archive() below).
    public function delete(User $user, Model $model): bool
    {
        return $user->role->atLeast(Role::Admin)
            && $model instanceof Event
            && ! $model->hasRecordedActivity();
    }

    public function deleteAny(User $user): bool
    {
        return $user->role->atLeast(Role::Admin);
    }

    public function archive(User $user, Event $event): bool
    {
        return $user->role->atLeast(Role::Admin) && $event->canBeArchived();
    }

    public function restore(User $user, Event $event): bool
    {
        return $user->role->atLeast(Role::Admin) && $event->isArchived();
    }
}
