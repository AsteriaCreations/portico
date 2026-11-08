<?php

namespace App\Models;

use Database\Factories\PrivateRentalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * How many people get in on the rental: the room's included people,
     * or the headcount the renter paid for when that's more. The renter is
     * one of them.
     */
    public function coveredSpots(): int
    {
        return max($this->included_people, $this->headcount);
    }

    /**
     * How many covered spots are used: everyone checked in to the rental
     * (the renter included), first come, first served. $except leaves one
     * person out, so pricing their own visit doesn't count it twice.
     */
    public function spotsTaken(?Member $except = null): int
    {
        return Attendance::where('event_id', $this->event_id)
            ->when($except, fn ($query) => $query->where('member_id', '!=', $except->id))
            ->count();
    }

    /**
     * Payments and refunds, append-only. See PrivateRentalService::netPaidCents().
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PrivateRentalPayment::class);
    }
}
