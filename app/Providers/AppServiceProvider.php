<?php

namespace App\Providers;

use App\Enums\Capability;
use App\Enums\Role;
use App\Models\Member;
use App\Models\MembershipSetting;
use App\Models\User;
use App\Observers\MemberObserver;
use App\Observers\UserObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Staff logins are the keys to check-in, payments, and member PII, so
        // hold every set/reset password to a real strength bar. No
        // ->uncompromised() (HIBP) check: the target deployment is a LAN box
        // with no guaranteed outbound internet, where that rule fails open
        // silently -- worse than not claiming the check at all.
        Password::defaults(fn (): Password => Password::min(12)->mixedCase()->numbers()->symbols());

        Gate::define('view-sensitive-member-fields', fn (User $user): bool => $user->role->atLeast(Role::Manager));

        // Manager and Owner, not Admin — the deliberate exception to the
        // nested role model. Owner sits above Admin in atLeast() but still
        // needs this explicit allow-list since Admin is excluded in between.
        // See docs/BLUEPRINT.md "Monthly Manager Subscription perk".
        Gate::define('grant-manager-subscription-perk', fn (User $user): bool => in_array($user->role, [Role::Manager, Role::Owner], true)
            && MembershipSetting::current()->manager_perk_enabled);

        Gate::define('grant-event-comp', fn (User $user): bool => $user->role->atLeast(Role::Manager));

        // Stronger than MemberResource's own Manager+ policy floor -- a
        // Manager can edit everything else on the form, but only Admin+ can
        // associate a skill with a member. See MemberForm's skills field,
        // hidden (and, per Filament's own saveRelationships() guard on
        // isHidden(), not synced) for anyone below this.
        Gate::define('assign-member-skills', fn (User $user): bool => $user->role->atLeast(Role::Admin));

        Gate::define('record-departures', fn (User $user): bool => $user->role->atLeast(Role::Volunteer));

        // Distinct from record-departures and from each other despite
        // sharing both a role floor and a page (ActivePatrons) -- same
        // idiom as grant-event-comp being its own gate rather than
        // overloading a general Manager+ check. The flag is baked directly
        // into each gate (same shape as grant-manager-subscription-perk
        // below) since both actions are standalone -- their entire purpose
        // is the flagged feature, so a forged call gets a hard 403 via the
        // existing abort_unless(Gate::allows(...)) in each action closure,
        // not a silent no-op.
        Gate::define('manage-visit-notes', fn (User $user): bool => $user->role->atLeast(Role::Volunteer)
            && MembershipSetting::current()->visit_notes_enabled);

        Gate::define('manage-behavior-notes', fn (User $user): bool => $user->role->atLeast(Role::Volunteer)
            && MembershipSetting::current()->behavior_notes_enabled);

        // Owner-only, not Manager+ like the rest of MembershipSettings --
        // the panel's displayed name is club identity, not an operational
        // tunable. Ordinary atLeast() monotonicity (Owner already outranks
        // everyone), unlike grant-manager-subscription-perk's allow-list.
        Gate::define('manage-org-name', fn (User $user): bool => $user->role->atLeast(Role::Owner));

        // Orthogonal to role -- a capability grants nothing rank-related on
        // its own. See App\Enums\Capability.
        Gate::define('access-cleaning-checklist', fn (User $user): bool => $user->hasCapability(Capability::CleaningCrew));

        Member::observe(MemberObserver::class);
        User::observe(UserObserver::class);
    }
}
