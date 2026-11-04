<?php

namespace Database\Factories;

use App\Models\AttendanceBehaviorNote;
use App\Models\BehaviorNoteRetraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BehaviorNoteRetraction>
 */
class BehaviorNoteRetractionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_behavior_note_id' => AttendanceBehaviorNote::factory(),
            'reason' => fake()->sentence(),
            'retracted_by' => User::factory(),
        ];
    }
}
