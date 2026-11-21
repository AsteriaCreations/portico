<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The door discount one visit got -- see DoorDiscount. Append-only: name and
 * applied amount are snapshotted, never edited.
 */
#[Fillable(['attendance_id', 'door_discount_id', 'name', 'amount'])]
class AttendanceDiscount extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function doorDiscount(): BelongsTo
    {
        return $this->belongsTo(DoorDiscount::class);
    }
}
