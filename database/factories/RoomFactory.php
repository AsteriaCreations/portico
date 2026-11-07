<?php

namespace Database\Factories;

use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'hourly_rate' => 50,
            'included_people' => 10,
            'overage_fee' => 5,
            'overage_per_hour' => true,
            'min_hours' => 1,
            'max_hours' => 8,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
