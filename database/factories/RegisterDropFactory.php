<?php

namespace Database\Factories;

use App\Models\RegisterDrop;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegisterDrop>
 */
class RegisterDropFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'register_shift_id' => RegisterShift::factory(),
            'amount' => 50,
            'reason' => fake()->sentence(),
            'recorded_by' => User::factory(),
        ];
    }
}
