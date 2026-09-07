<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends RoleGatedPolicy
{
    protected Role $minimumRole = Role::Admin;

    /**
     * Hide the delete button for the two cases UserObserver hard-blocks:
     * deleting your own account, and deleting the last active Owner. The
     * observer is the real guard (it also covers tinker / a forged call);
     * this just keeps the UI honest. UsersTable's DeleteBulkAction pairs
     * this with ->authorizeIndividualRecords('delete') so bulk delete can't
     * route around it.
     */
    public function delete(User $user, Model $model): bool
    {
        if (! parent::delete($user, $model)) {
            return false;
        }

        if ($user->id === $model->getKey()) {
            return false;
        }

        return ! $this->isLastActiveOwner($model);
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
