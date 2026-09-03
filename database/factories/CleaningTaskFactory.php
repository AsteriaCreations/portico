<?php

namespace Database\Factories;

use App\Models\CleaningTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CleaningTask>
 */
class CleaningTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
