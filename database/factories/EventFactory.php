<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventType;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'name' => fake()->words(3, true),
            'event_type_id' => EventType::factory(),
            'entry_fee' => fake()->randomFloat(2, 0, 100),
            'pool_fee' => 0,
            'notes' => null,
            'created_by' => null,
        ];
    }
}
