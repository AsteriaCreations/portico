<?php

namespace Database\Factories;

use App\Models\AddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddOn>
 */
class AddOnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'price' => fake()->randomFloat(2, 5, 100),
            'max_per_night' => null,
            'is_overnight' => false,
            'description' => fake()->sentence(),
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
