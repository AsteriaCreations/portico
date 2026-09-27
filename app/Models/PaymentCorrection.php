<?php

namespace App\Models;

use Database\Factories\PaymentCorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One same-night conversion of a paid door entry into a subscription. Written
 * only by App\Services\EntryCorrectionService; see PaymentCorrectionPolicy.
 */
#[Fillable([
    'attendance_id', 'subscription_id', 'register_shift_id', 'payment_method',
    'old_amount_paid', 'new_amount_paid', 'subscription_amount', 'net_amount', 'reason', 'corrected_by',
])]
class PaymentCorrection extends Model
{
    /** @use HasFactory<PaymentCorrectionFactory> */
    use HasFactory;

    // Append-only audit trail: rows are never edited.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_amount_paid' => 'decimal:2',
            'new_amount_paid' => 'decimal:2',
            'subscription_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
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
