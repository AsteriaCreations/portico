<?php

namespace App\Models;

use Database\Factories\ShiftTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One shift every event of an event type needs: the role, when it starts in
 * minutes from the event's start (negative = before doors), how long it runs
 * and how many people. ShiftSlotService copies these onto each event as
 * ShiftSlot rows, so editing a template never moves a shift already made.
 */
#[Fillable(['event_type_id', 'shift_role_id', 'start_offset_minutes', 'duration_minutes', 'headcount'])]
class ShiftTemplate extends Model
{
    /** @use HasFactory<ShiftTemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'start_offset_minutes' => 'integer',
            'duration_minutes' => 'integer',
            'headcount' => 'integer',
        ];
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function shiftRole(): BelongsTo
    {
        return $this->belongsTo(ShiftRole::class);
    }
}
