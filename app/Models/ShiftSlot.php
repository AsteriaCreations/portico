<?php

namespace App\Models;

use Database\Factories\ShiftSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shift on one event that staff give availability for and get assigned
 * to: a role, its own start and end, and how many people it needs.
 */
#[Fillable(['event_id', 'shift_role_id', 'starts_at', 'ends_at', 'headcount', 'notes'])]
class ShiftSlot extends Model
{
    /** @use HasFactory<ShiftSlotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'headcount' => 'integer',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function shiftRole(): BelongsTo
    {
        return $this->belongsTo(ShiftRole::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(ShiftAvailability::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }
}
