<?php

namespace App\Models;

use Database\Factories\AddOnDayPassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'event_id', 'add_on_id', 'amount_paid', 'payment_method', 'register_shift_id', 'recorded_by'])]
class AddOnDayPass extends Model
{
    /** @use HasFactory<AddOnDayPassFactory> */
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

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(AddOn::class);
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
