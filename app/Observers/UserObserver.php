<?php

namespace App\Observers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Server-side guards against a staff account locking itself — or the whole
 * installation — out of the roles that keep it running. Enforced at the model
 * layer so they hold no matter which save path triggered the change (the
 * Filament Users form, tinker, a future API), the same reasoning behind
 * MemberObserver. UserPolicy hides the matching UI buttons; this is the
 * backstop for a forged or scripted call that gets past that. Rank matters
 * too: nobody changes an account, or grants a role, above their own.
 *
 * A ValidationException surfaces as an inline form error (Livewire catches it
 * and aborts the save before the write) rather than a 500.
 */
class UserObserver
{
    public function updating(User $user): void
    {
        // No authenticated actor means a console-driven write — a seeder, the
        // roster import, a maintenance script. Nothing to lock out, and the
        // same exemption MemberObserver makes for attributed audit rows.
        if (! auth()->check()) {
            return;
        }

        $this->guardOutrankedTarget($user);
        $this->guardRoleGrant($user);
        $this->guardSelfLockout($user);
        $this->guardLastActiveOwner(
            $user,
            __('The last active Owner can’t be demoted or deactivated. Promote another Owner first.'),
        );
    }

    public function deleting(User $user): void
    {
        if (! auth()->check()) {
            return;
        }

        if (auth()->id() === $user->id) {
            throw ValidationException::withMessages([
                'record' => __('You can’t delete your own account.'),
            ]);
        }

        $this->guardOutrankedTarget($user);
        $this->guardLastActiveOwner(
            $user,
            __('The last active Owner can’t be deleted. Promote another Owner first.'),
            isDeletion: true,
        );
    }

    /**
     * Nobody changes or deletes an account ranked above their own — an Admin
     * can't reset, deactivate or edit an Owner. Checked against the role the
     * account had before this save, so demoting it first isn't a way round.
     */
    private function guardOutrankedTarget(User $user): void
    {
        $targetRole = $this->normalizeRole($user->getOriginal('role'));

        if ($targetRole !== null && ! $this->actorRole()->atLeast($targetRole)) {
            throw ValidationException::withMessages([
                'record' => __('You can’t change an account that outranks you.'),
            ]);
        }
    }

    /**
     * Nobody promotes an account above their own role, themselves included
     * -- an Admin can't make anyone an Owner, unless there's no active Owner
     * at all (User::canGrantRole()). Creating an account with such a role is
     * refused by CreateUser (the role picker only offers grantable roles, and
     * Filament rejects any value outside them).
     */
    private function guardRoleGrant(User $user): void
    {
        $role = $this->normalizeRole($user->role);

        if ($user->isDirty('role') && $role !== null && ! User::canGrantRole($this->actorRole(), $role)) {
            throw ValidationException::withMessages([
                'role' => __('You can’t give an account a role above your own.'),
            ]);
        }
    }

    /**
     * The actor's role as saved, not as it may be about to change: when you
     * edit your own account, auth()->user() is the very model being saved,
     * and reading ->role would let an Admin rank themselves as the Owner
     * they're trying to become.
     */
    private function actorRole(): Role
    {
        return $this->normalizeRole(auth()->user()->getOriginal('role')) ?? auth()->user()->role;
    }

    /**
     * You can't strip your own access — deactivating yourself, or dropping
     * your own role below Admin (which manages the Users resource) — in a
     * single save you might not be able to undo.
     */
    private function guardSelfLockout(User $user): void
    {
        if (auth()->id() !== $user->id) {
            return;
        }

        if ($user->isDirty('active') && ! $user->active) {
            throw ValidationException::withMessages([
                'active' => __('You can’t deactivate your own account.'),
            ]);
        }

        if ($user->isDirty('role') && ! $user->role->atLeast(Role::Admin)) {
            throw ValidationException::withMessages([
                'role' => __('You can’t lower your own role below Admin.'),
            ]);
        }
    }

    /**
     * The installation must always keep at least one active Owner — several
     * gates (the Manager/Owner perk, org-name editing) have no other holder.
     * Blocks demoting, deactivating, or deleting the final one.
     */
    private function guardLastActiveOwner(User $user, string $message, bool $isDeletion = false): void
    {
        $wasActiveOwner = $this->normalizeRole($user->getOriginal('role')) === Role::Owner
            && (bool) $user->getOriginal('active') === true;

        if (! $wasActiveOwner) {
            return;
        }

        $stillAnActiveOwner = ! $isDeletion
            && $this->normalizeRole($user->role) === Role::Owner
            && $user->active;

        if ($stillAnActiveOwner) {
            return;
        }

        $otherActiveOwners = User::query()
            ->where('role', Role::Owner->value)
            ->where('active', true)
            ->whereKeyNot($user->getKey())
            ->exists();

        if (! $otherActiveOwners) {
            throw ValidationException::withMessages([
                $isDeletion ? 'record' : 'role' => $message,
            ]);
        }
    }

    /**
     * getOriginal() applies the model's casts on current Laravel, but a
     * factory or raw insert can still leave a bare string in the attribute —
     * normalize both shapes to the enum.
     */
    private function normalizeRole(Role|string|null $role): ?Role
    {
        return $role instanceof Role ? $role : ($role === null ? null : Role::tryFrom($role));
    }
}
