<?php

namespace App\Policies;

use App\Models\AddOn;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AddOnPolicy extends RoleGatedPolicy
{
    // Also hides the resource entirely (nav item + direct page access, via
    // Resource::canAccess() -> canViewAny()) when the club has add-ons
    // turned off -- see App\Filament\Admin\Pages\FeatureFlags.
    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->add_ons_enabled;
    }

    // The one protected 'entry' row can never be deleted -- every Regular
    // subscription targets it (see the add_ons migration's own comment),
    // same protection Category::PROTECTED_NAMES gives Prospective/Guest/
    // Irregular.
    public function delete(User $user, Model $model): bool
    {
        if ($model instanceof AddOn && $model->name === AddOn::ENTRY_NAME) {
            return false;
        }

        return parent::delete($user, $model);
    }
}
