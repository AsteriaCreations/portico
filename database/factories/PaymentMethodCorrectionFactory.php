<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\PaymentMethodCorrection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethodCorrection>
 */
class PaymentMethodCorrectionFactory extends Factory
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
            'register_shift_id' => null,
            'old_payment_method' => 'venmo',
            'new_payment_method' => 'cash',
            'amount' => 40,
            'reason' => fake()->sentence(),
            'corrected_by' => User::factory(),
        ];
    }
}
