<?php

namespace App\Models;

use Database\Factories\PrivateRentalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room booked for a member's private rental, with the room's prices as
 * they were when booked. Written only by PrivateRentalService.
 */
#[Fillable(['room_id', 'member_id', 'event_id', 'starts_at', 'ends_at', 'hours', 'headcount', 'hourly_rate', 'included_people', 'overage_fee', 'overage_per_hour', 'room_cost', 'extra_people_cost', 'total', 'booked_by', 'cancelled_at', 'cancelled_by'])]
class PrivateRental extends Model
{
    /** @use HasFactory<PrivateRentalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hours' => 'integer',
            'headcount' => 'integer',
            'hourly_rate' => 'decimal:2',
            'included_people' => 'integer',
            'overage_fee' => 'decimal:2',
            'overage_per_hour' => 'boolean',
            'room_cost' => 'decimal:2',
            'extra_people_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Bookings that still hold their room.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notCancelled(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
