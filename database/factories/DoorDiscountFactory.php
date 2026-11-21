<?php

namespace Database\Factories;

use App\Models\DoorDiscount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoorDiscount>
 */
class DoorDiscountFactory extends Factory
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
            'amount' => 5,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
