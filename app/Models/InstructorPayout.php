<?php

namespace App\Models;

use Database\Factories\InstructorPayoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash paid to an event's instructor out of a register shift's box. One per
 * event from the desk; a Manager+ correction is a further (possibly
 * negative) row. See RegisterShiftService::recordInstructorPayout().
 */
#[Fillable(['event_id', 'register_shift_id', 'amount', 'calculated_amount', 'notes', 'recorded_by'])]
class InstructorPayout extends Model
{
    /** @use HasFactory<InstructorPayoutFactory> */
    use HasFactory;

    // Append-only ledger: rows are never edited, only offset by a new row.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'calculated_amount' => 'decimal:2',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registerShift(): BelongsTo
    {
        return $this->belongsTo(RegisterShift::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
