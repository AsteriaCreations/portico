<?php

namespace Database\Factories;

use App\Models\CleaningTask;
use App\Models\CleaningTaskCompletion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CleaningTaskCompletion>
 */
class CleaningTaskCompletionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cleaning_task_id' => CleaningTask::factory(),
            'completed_by' => User::factory(),
            'for_week_start' => now()->startOfWeek()->toDateString(),
            'notes' => null,
        ];
    }
}
