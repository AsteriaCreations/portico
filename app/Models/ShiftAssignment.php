<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person on one shift. Scheduled through ShiftScheduleService; marked
 * worked (worked_at) through ShiftWorkService, by signing in during the
 * shift or by a Manager+ afterwards.
 */
#[Fillable(['shift_slot_id', 'user_id', 'assigned_by', 'worked_at', 'worked_source', 'worked_recorded_by'])]
class ShiftAssignment extends Model
{
    public const WORKED_BY_SIGN_IN = 'sign_in';

    public const WORKED_BY_MANAGER = 'manager';

    protected function casts(): array
    {
        return [
            'worked_at' => 'datetime',
        ];
    }

    public function shiftSlot(): BelongsTo
    {
        return $this->belongsTo(ShiftSlot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function workedRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worked_recorded_by');
    }
}
