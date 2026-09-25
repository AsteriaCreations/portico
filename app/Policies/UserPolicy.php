<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends RoleGatedPolicy
{
    protected Role $minimumRole = Role::Admin;

    /**
     * Nobody edits an account ranked above their own -- an Admin can see
     * Owners in the list but not change them. UserObserver enforces the same
     * rule on every save; this hides the Edit button and blocks the URL.
     */
    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model) && $this->outranksOrMatches($user, $model);
    }

    /**
     * Hide the delete button for the cases UserObserver hard-blocks:
     * deleting your own account, an account that outranks you, and the last
     * active Owner. The observer is the real guard (it also covers tinker /
     * a forged call); this just keeps the UI honest. UsersTable's
     * DeleteBulkAction pairs this with ->authorizeIndividualRecords('delete')
     * so bulk delete can't route around it.
     */
    public function delete(User $user, Model $model): bool
    {
        if (! parent::delete($user, $model) || ! $this->outranksOrMatches($user, $model)) {
            return false;
        }

        if ($user->id === $model->getKey()) {
            return false;
        }

        return ! $this->isLastActiveOwner($model);
    }

    /**
     * Setting someone's password to a temporary one. Never your own: you
     * know your password, so use the Change password page instead.
     */
    public function resetPassword(User $user, User $target): bool
    {
        return $user->role->atLeast($this->minimumRole)
            && $user->id !== $target->id
            && $this->outranksOrMatches($user, $target);
    }

    private function outranksOrMatches(User $user, Model $model): bool
    {
        return $model instanceof User && $user->role->atLeast($model->role);
    }

    private function isLastActiveOwner(Model $model): bool
    {
        if ($model->role !== Role::Owner || ! $model->active) {
            return false;
        }

        return ! User::query()
            ->where('role', Role::Owner->value)
            ->where('active', true)
            ->whereKeyNot($model->getKey())
            ->exists();
    }
}
