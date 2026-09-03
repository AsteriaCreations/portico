<?php

namespace Database\Factories;

use App\Models\MiscellaneousPayment;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MiscellaneousPayment>
 */
class MiscellaneousPaymentFactory extends Factory
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
            'payment_method' => 'cash',
            'amount' => 25,
            'notation' => fake()->sentence(),
            'recorded_by' => User::factory(),
        ];
    }
}
