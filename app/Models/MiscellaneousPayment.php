<?php

namespace App\Models;

use Database\Factories\MiscellaneousPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['register_shift_id', 'payment_method', 'amount', 'notation', 'recorded_by'])]
class MiscellaneousPayment extends Model
{
    /** @use HasFactory<MiscellaneousPaymentFactory> */
    use HasFactory;

    // Append-only ledger: rows are never edited, only offset by a new row.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
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
