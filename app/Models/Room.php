<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room a member can book for a private rental at the desk. Its prices are
 * read when a booking is quoted and snapshotted onto the booking, so editing
 * a room never changes a booking already made.
 */
#[Fillable(['name', 'description', 'hourly_rate', 'included_people', 'overage_fee', 'overage_per_hour', 'min_hours', 'max_hours', 'sort_order', 'active'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:2',
            'included_people' => 'integer',
            'overage_fee' => 'decimal:2',
            'overage_per_hour' => 'boolean',
            'min_hours' => 'integer',
            'max_hours' => 'integer',
            'sort_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function privateRentals(): HasMany
    {
        return $this->hasMany(PrivateRental::class);
    }
}
