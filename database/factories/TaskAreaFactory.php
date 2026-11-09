<?php

namespace Database\Factories;

use App\Models\TaskArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskArea>
 */
class TaskAreaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'notes' => null,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
