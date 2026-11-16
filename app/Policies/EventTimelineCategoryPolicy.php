<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\EventTimelineCategory;
use App\Models\MembershipSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin+ (who also manages events), hidden while event timelines are off.
 * A category still used by an item can't be deleted: the item would lose
 * who it's for.
 */
class EventTimelineCategoryPolicy extends RoleGatedPolicy
{
    protected Role $minimumRole = Role::Admin;

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && MembershipSetting::current()->event_timelines_enabled;
    }

    public function delete(User $user, Model $model): bool
    {
        /** @var EventTimelineCategory $model */
        return parent::delete($user, $model) && ! $model->items()->exists();
    }
}
