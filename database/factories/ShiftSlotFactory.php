<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\ShiftRole;
use App\Models\ShiftSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftSlot>
 */
class ShiftSlotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = today()->setTime(20, 0);

        return [
            'event_id' => Event::factory(),
            'shift_role_id' => ShiftRole::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(4),
            'headcount' => 1,
            'notes' => null,
        ];
    }
}
