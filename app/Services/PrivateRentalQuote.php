<?php

namespace App\Services;

use App\Models\Room;
use App\Support\Cents;

/**
 * What a private rental costs, in integer cents (see App\Support\Cents):
 * the room for every hour booked, plus each person past the room's
 * included_people at its overage fee (times the hours when the fee is
 * per hour). Built only by PrivateRentalService::quote().
 */
final readonly class PrivateRentalQuote
{
    public function __construct(
        public Room $room,
        public int $hours,
        public int $headcount,
        public int $roomCostCents,
        public int $extraPeople,
        public int $perExtraPersonCents,
        public int $extraPeopleCostCents,
        public int $totalCents,
    ) {}

    /**
     * The booking's price snapshot columns, ready for PrivateRental::create().
     *
     * @return array<string, mixed>
     */
    public function toRentalAttributes(): array
    {
        return [
            'hours' => $this->hours,
            'headcount' => $this->headcount,
            'hourly_rate' => $this->room->hourly_rate,
            'included_people' => $this->room->included_people,
            'overage_fee' => $this->room->overage_fee,
            'overage_per_hour' => $this->room->overage_per_hour,
            'room_cost' => Cents::toDecimal($this->roomCostCents),
            'extra_people_cost' => Cents::toDecimal($this->extraPeopleCostCents),
            'total' => Cents::toDecimal($this->totalCents),
        ];
    }
}
