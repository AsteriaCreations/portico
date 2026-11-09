<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member's note for one schedule week ("leaving early Saturday"),
 * keyed on the week's first day.
 */
#[Fillable(['user_id', 'entered_by', 'week_starts_on', 'note'])]
class ShiftWeekNote extends Model
{
    protected function casts(): array
    {
        return [
            'week_starts_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who entered it: the person themselves, or a Manager+ for them.
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
