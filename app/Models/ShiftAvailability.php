<?php

namespace App\Models;

use App\Enums\ShiftPreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff member's answer for one shift. No row means they haven't
 * answered. Written only through ShiftAvailabilityService.
 */
#[Fillable(['shift_slot_id', 'user_id', 'entered_by', 'preference'])]
class ShiftAvailability extends Model
{
    protected function casts(): array
    {
        return [
            'preference' => ShiftPreference::class,
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

    /**
     * Who entered it: the person themselves, or a Manager+ for them.
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
