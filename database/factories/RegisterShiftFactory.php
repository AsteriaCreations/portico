<?php

namespace Database\Factories;

use App\Models\Register;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegisterShift>
 */
class RegisterShiftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'register_id' => Register::factory(),
            'opened_by' => User::factory(),
            'opening_count' => 100,
            'closed_by' => null,
            'closed_at' => null,
            'closing_count' => null,
            'notes' => null,
        ];
    }
}
