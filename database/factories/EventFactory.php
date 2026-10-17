<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->date();

        return [
            'event_date' => $date,
            'starts_at' => "{$date} 20:00:00",
            'ends_at' => "{$date} 23:00:00",
            // Open through the night after event_date, so a test that only
            // overrides event_date never finds check-in already closed. Tests
            // of the close itself set it explicitly.
            'checkin_closes_at' => fn (array $attributes): Carbon => Carbon::parse($attributes['event_date'])->addDay()->endOfDay(),
            'name' => fake()->words(3, true),
            'event_type_id' => EventType::factory(),
            'entry_fee' => fake()->randomFloat(2, 0, 100),
            'pool_fee' => 0,
            'notes' => null,
            'created_by' => null,
        ];
    }
}
