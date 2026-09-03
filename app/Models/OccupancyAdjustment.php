<?php

namespace App\Models;

use Database\Factories\OccupancyAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['for_date', 'delta', 'reason', 'recorded_by'])]
class OccupancyAdjustment extends Model
{
    /** @use HasFactory<OccupancyAdjustmentFactory> */
    use HasFactory;

    // Append-only ledger: rows are never edited, only offset by a new row.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'for_date' => 'date',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
