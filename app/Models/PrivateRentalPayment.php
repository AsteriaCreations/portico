<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment (positive) or refund (negative) for a private rental.
 * Append-only (PrivateRentalPaymentPolicy); written only by
 * PrivateRentalService.
 */
#[Fillable(['private_rental_id', 'amount', 'transaction_fee', 'payment_method', 'register_shift_id', 'reason', 'recorded_by'])]
class PrivateRentalPayment extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_fee' => 'decimal:2',
        ];
    }

    public function privateRental(): BelongsTo
    {
        return $this->belongsTo(PrivateRental::class);
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
