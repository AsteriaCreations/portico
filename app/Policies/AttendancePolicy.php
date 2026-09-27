<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;
use App\Services\VisitRemovalService;
use Illuminate\Database\Eloquent\Model;

class AttendancePolicy extends RoleGatedPolicy
{
    /**
     * Only a $0 visit with nothing pointing at it can go through a generic
     * delete path (a bulk delete). A paid visit is removed only through
     * VisitRemovalService, which asks for a reason and logs it.
     */
    public function delete(User $user, Model $model): bool
    {
        $service = app(VisitRemovalService::class);

        return $model instanceof Attendance
            && ! $service->isPaid($model)
            && $service->refusalReason($model, $user) === null;
    }
}
