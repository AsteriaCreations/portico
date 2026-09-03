<?php

namespace Database\Factories;

use App\Models\AddOn;
use App\Models\Attendance;
use App\Models\AttendanceAddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceAddOn>
 */
class AttendanceAddOnFactory extends Factory
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
            'add_on_id' => AddOn::factory(),
            'name' => fake()->word(),
            'price' => fake()->randomFloat(2, 5, 100),
            'is_overnight' => false,
        ];
    }
}
