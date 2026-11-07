<?php

namespace App\Observers;

use App\Models\CompReason;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the manage-door-comp-reasons gate on every save path (the
 * resource form, the bulk importer, tinker), not just the form's disabled
 * toggle. Turning door_allowed on hands Door a comp it otherwise can't
 * grant, so only Admin+ may change it. A console save with nobody signed in
 * (seeders, imports) is left alone, same as MemberObserver.
 */
class CompReasonObserver
{
    public function saving(CompReason $compReason): void
    {
        $changingDoorAccess = $compReason->exists
            ? $compReason->isDirty('door_allowed')
            : (bool) $compReason->door_allowed;

        if ($changingDoorAccess && auth()->check() && Gate::denies('manage-door-comp-reasons')) {
            throw ValidationException::withMessages([
                'door_allowed' => __('Only an Admin can choose which comp reasons Door staff may use.'),
            ]);
        }
    }
}
