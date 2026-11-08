<?php

namespace App\Services;

use App\Support\Cents;

/**
 * What a private rental costs, in integer cents (see App\Support\Cents):
 * the room for every hour booked, plus each person past the people included
 * at the overage fee (times the hours when the fee is per hour). Built only
 * by PrivateRentalService, from a room's current prices for a new booking
 * or from a booking's own snapshot when it's changed.
 */
final readonly class PrivateRentalQuote
{
    public function __construct(
        public int $hourlyRateCents,
        public int $includedPeople,
        public int $overageFeeCents,
        public bool $overagePerHour,
        public int $hours,
        public int $headcount,
        public int $roomCostCents,
        public int $extraPeople,
        public int $perExtraPersonCents,
        public int $extraPeopleCostCents,
        public int $totalCents,
    ) {}

    /**
     * The booking's price snapshot columns, ready for PrivateRental::create()
     * or update().
     *
     * @return array<string, mixed>
     */
    public function toRentalAttributes(): array
    {
        return [
            'hours' => $this->hours,
            'headcount' => $this->headcount,
            'hourly_rate' => Cents::toDecimal($this->hourlyRateCents),
            'included_people' => $this->includedPeople,
            'overage_fee' => Cents::toDecimal($this->overageFeeCents),
            'overage_per_hour' => $this->overagePerHour,
            'room_cost' => Cents::toDecimal($this->roomCostCents),
            'extra_people_cost' => Cents::toDecimal($this->extraPeopleCostCents),
            'total' => Cents::toDecimal($this->totalCents),
        ];
    }
}
