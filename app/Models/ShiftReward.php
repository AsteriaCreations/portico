<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A voucher a Manager+ granted someone for working beyond the monthly
 * shift requirement. One per person per month; append-only, with no
 * screen that edits it. Written only through ShiftRewardService.
 */
#[Fillable(['user_id', 'month', 'shifts_worked', 'shifts_beyond', 'amount', 'voucher_id', 'note', 'granted_by'])]
class ShiftReward extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'shifts_worked' => 'integer',
            'shifts_beyond' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
