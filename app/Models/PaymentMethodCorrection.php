<?php

namespace App\Models;

use Database\Factories\PaymentMethodCorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fix of a visit's payment method at the desk, with the amount left
 * alone. Written only by App\Services\PaymentMethodCorrectionService; see
 * PaymentMethodCorrectionPolicy.
 */
#[Fillable([
    'attendance_id', 'register_shift_id', 'old_payment_method', 'new_payment_method', 'amount', 'reason', 'corrected_by',
])]
class PaymentMethodCorrection extends Model
{
    /** @use HasFactory<PaymentMethodCorrectionFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function registerShift(): BelongsTo
    {
        return $this->belongsTo(RegisterShift::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
