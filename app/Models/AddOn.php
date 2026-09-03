<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\AddOnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'price', 'max_per_night', 'is_overnight', 'description', 'sort_order', 'active'])]
class AddOn extends Model
{
    /** @use HasFactory<AddOnFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'max_per_night' => 'integer',
            'is_overnight' => 'boolean',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function attendanceAddOns(): HasMany
    {
        return $this->hasMany(AttendanceAddOn::class);
    }

    /**
     * Building-wide across every concurrent event that night, not per event
     * -- a physical resource like the one rentable room is shared venue-wide,
     * the same scope CapacityService::occupancy() uses for the building
     * itself. A prepay counts immediately, same as occupancy, since the sale
     * is already committed regardless of whether the attendee has arrived.
     */
    public function unitsSoldOn(CarbonInterface $date): int
    {
        return $this->attendanceAddOns()
            ->whereHas('attendance.event', fn ($query) => $query->whereDate('event_date', $date))
            ->count();
    }

    public function hasRoomOn(CarbonInterface $date): bool
    {
        return $this->max_per_night === null || $this->unitsSoldOn($date) < $this->max_per_night;
    }
}
