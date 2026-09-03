<?php

namespace App\Models;

use Database\Factories\PoolDayPassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'event_id', 'amount_paid', 'payment_method', 'register_shift_id', 'recorded_by'])]
class PoolDayPass extends Model
{
    /** @use HasFactory<PoolDayPassFactory> */
    use HasFactory;

    // Append-only: rows are never edited, only ever created.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
