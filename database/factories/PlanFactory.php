<?php

namespace Database\Factories;

use App\Models\AddOn;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'add_on_id' => AddOn::factory(),
            'duration_months' => 1,
            'price' => fake()->randomFloat(2, 10, 100),
            'credit' => null,
            'effective_from' => fake()->date(),
            'effective_to' => null,
        ];
    }
}
