<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CategoryPolicy extends RoleGatedPolicy
{
    // Prospective/Guest/Irregular are string-matched directly by business
    // logic elsewhere (see Category::PROTECTED_NAMES) — deleting one of
    // those rows would silently break that code, so it's blocked here
    // server-side rather than only hidden in the UI.
    public function delete(User $user, Model $model): bool
    {
        if ($model instanceof Category && in_array($model->name, Category::PROTECTED_NAMES, true)) {
            return false;
        }

        return parent::delete($user, $model);
    }
}
