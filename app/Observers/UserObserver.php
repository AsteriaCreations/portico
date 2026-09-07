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
 * backstop for a forged or scripted call that gets past that.
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

        $this->guardSelfLockout($user);
        $this->guardLastActiveOwner(
            $user,
            'The last active Owner can’t be demoted or deactivated. Promote another Owner first.',
        );
    }

    public function deleting(User $user): void
    {
        if (! auth()->check()) {
            return;
        }

        if (auth()->id() === $user->id) {
            throw ValidationException::withMessages([
                'record' => 'You can’t delete your own account.',
            ]);
        }

        $this->guardLastActiveOwner(
            $user,
            'The last active Owner can’t be deleted. Promote another Owner first.',
            isDeletion: true,
        );
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
                'active' => 'You can’t deactivate your own account.',
            ]);
        }

        if ($user->isDirty('role') && ! $user->role->atLeast(Role::Admin)) {
            throw ValidationException::withMessages([
                'role' => 'You can’t lower your own role below Admin.',
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
