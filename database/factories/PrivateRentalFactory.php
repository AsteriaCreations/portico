<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Member;
use App\Models\PrivateRental;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A booking with its own event, two hours from tomorrow at 7pm, priced from
 * the factory room's defaults. Tests that care about the price or the
 * overlap check set the times and amounts they need.
 *
 * @extends Factory<PrivateRental>
 */
class PrivateRentalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = today()->addDay()->setTime(19, 0);
        $endsAt = $startsAt->copy()->addHours(2);

        return [
            'room_id' => Room::factory(),
            'member_id' => Member::factory(),
            'event_id' => fn (array $attributes) => Event::factory()->create([
                'event_date' => $startsAt->toDateString(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'host_id' => $attributes['member_id'],
            ]),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'hours' => 2,
            'headcount' => 10,
            'hourly_rate' => 50,
            'included_people' => 10,
            'overage_fee' => 5,
            'overage_per_hour' => true,
            'room_cost' => 100,
            'extra_people_cost' => 0,
            'total' => 100,
            'booked_by' => User::factory(),
        ];
    }
}
