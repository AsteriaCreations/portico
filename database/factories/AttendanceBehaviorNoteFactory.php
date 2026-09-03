<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\AttendanceBehaviorNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceBehaviorNote>
 */
class AttendanceBehaviorNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_id' => Attendance::factory(),
            'note' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
