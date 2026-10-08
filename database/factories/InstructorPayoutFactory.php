<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\InstructorPayout;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorPayout>
 */
class InstructorPayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'register_shift_id' => RegisterShift::factory(),
            'amount' => 40,
            'calculated_amount' => 40,
            'notes' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
