<?php

namespace App\Models;

use Database\Factories\CashExchangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash handed out of a register shift's box to someone who paid the same
 * amount, plus the method's fee, electronically -- e.g. Venmo for change.
 * Not revenue. A Manager+ correction is a further (negative) row. See
 * RegisterShiftService::recordCashExchange().
 */
#[Fillable(['register_shift_id', 'payment_method', 'amount', 'fee', 'notes', 'recorded_by'])]
class CashExchange extends Model
{
    /** @use HasFactory<CashExchangeFactory> */
    use HasFactory;

    // Append-only ledger: rows are never edited, only offset by a new row.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
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
