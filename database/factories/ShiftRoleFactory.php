<?php

namespace Database\Factories;

use App\Models\ShiftRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftRole>
 */
class ShiftRoleFactory extends Factory
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
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
