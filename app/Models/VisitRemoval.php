<?php

namespace App\Models;

use Database\Factories\VisitRemovalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot of a paid visit that was removed. Written only by
 * App\Services\VisitRemovalService; see VisitRemovalPolicy.
 */
#[Fillable([
    'member_id', 'event_id', 'register_shift_id', 'payment_method', 'amount_paid',
    'checked_in_at', 'after_shift_closed', 'prepaid_ahead', 'reason', 'removed_by',
])]
class VisitRemoval extends Model
{
    /** @use HasFactory<VisitRemovalFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'checked_in_at' => 'datetime',
            'after_shift_closed' => 'boolean',
            'prepaid_ahead' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function registerShift(): BelongsTo
    {
        return $this->belongsTo(RegisterShift::class);
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
