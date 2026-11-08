<?php

namespace Database\Factories;

use App\Models\EventType;
use App\Models\ShiftRole;
use App\Models\ShiftTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftTemplate>
 */
class ShiftTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_type_id' => EventType::factory(),
            'shift_role_id' => ShiftRole::factory(),
            'start_offset_minutes' => 0,
            'duration_minutes' => 240,
            'headcount' => 1,
        ];
    }
}
