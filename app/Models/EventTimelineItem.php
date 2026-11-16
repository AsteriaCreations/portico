<?php

namespace App\Models;

use Database\Factories\EventTimelineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry on an event's run of show: "Doors open", "Raffle draw",
 * "Reset the main room". ends_at null is a moment rather than a span.
 * Written only through EventTimelineService.
 */
#[Fillable(['event_id', 'event_timeline_category_id', 'title', 'description', 'starts_at', 'ends_at', 'created_by', 'updated_by'])]
class EventTimelineItem extends Model
{
    /** @use HasFactory<EventTimelineItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EventTimelineCategory::class, 'event_timeline_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
