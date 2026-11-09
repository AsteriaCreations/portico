<?php

namespace Database\Factories;

use App\Enums\RoleTaskStatus;
use App\Models\RoleTask;
use App\Models\ShiftRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoleTask>
 */
class RoleTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shift_role_id' => ShiftRole::factory(),
            'task_area_id' => null,
            'name' => fake()->sentence(3),
            'description' => null,
            'status' => RoleTaskStatus::Standard,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
