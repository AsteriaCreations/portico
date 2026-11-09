<?php

namespace App\Models;

use App\Enums\ShiftKind;
use Database\Factories\ShiftSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shift on one event that staff give availability for and get assigned
 * to: a role, what it's for (kind, optional label), its own start and end,
 * and how many people it needs.
 */
#[Fillable(['event_id', 'shift_role_id', 'kind', 'label', 'starts_at', 'ends_at', 'headcount', 'notes'])]
class ShiftSlot extends Model
{
    /** @use HasFactory<ShiftSlotFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => ShiftKind::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'headcount' => 'integer',
        ];
    }

    /**
     * How the shift reads everywhere staff see it: "Desk – Open", "Setup",
     * "Door – Late cover". A whole-night shift is just its role; an extra
     * shift with no label is just its role too.
     */
    public function displayName(): string
    {
        $role = $this->shiftRole?->name ?? '';
        $detail = $this->label ?: match ($this->kind) {
            ShiftKind::Open, ShiftKind::Close, ShiftKind::Setup => $this->kind->getLabel(),
            default => null,
        };

        if ($detail === null || $detail === $role) {
            return $role;
        }

        return __(':role – :detail', ['role' => $role, 'detail' => $detail]);
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
