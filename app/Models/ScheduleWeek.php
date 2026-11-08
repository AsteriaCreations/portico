<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A schedule week that has been published to staff. No row = still a
 * draft only Admin+ sees.
 */
#[Fillable(['week_starts_on', 'published_at', 'published_by'])]
class ScheduleWeek extends Model
{
    protected function casts(): array
    {
        return [
            'week_starts_on' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
