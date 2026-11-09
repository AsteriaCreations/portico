<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role task ticked as done: for a scheduled role, on one shift
 * (shift_slot_id; on_shift false when the person ticking wasn't on it,
 * which shows as extra effort); for an unscheduled role, in one schedule
 * week (week_start, on_shift null). See RoleTaskService.
 */
#[Fillable(['role_task_id', 'user_id', 'shift_slot_id', 'week_start', 'on_shift', 'completed_at'])]
class RoleTaskCompletion extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'on_shift' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(RoleTask::class, 'role_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shiftSlot(): BelongsTo
    {
        return $this->belongsTo(ShiftSlot::class);
    }
}
