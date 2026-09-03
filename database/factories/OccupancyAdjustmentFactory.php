<?php

namespace Database\Factories;

use App\Models\OccupancyAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OccupancyAdjustment>
 */
class OccupancyAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'for_date' => now()->toDateString(),
            'delta' => -1,
            'reason' => fake()->sentence(),
            'recorded_by' => User::factory(),
        ];
    }
}
