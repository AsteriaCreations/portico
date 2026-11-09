<?php

namespace App\Models;

use App\Enums\ShiftPresence;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Off or Out for one person on one night. Written only through
 * ShiftAvailabilityService.
 */
#[Fillable(['user_id', 'entered_by', 'night', 'status'])]
class ShiftPresenceAnswer extends Model
{
    protected $table = 'shift_presences';

    protected function casts(): array
    {
        return [
            'night' => 'date',
            'status' => ShiftPresence::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
