<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\PaymentCorrection;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentCorrection>
 */
class PaymentCorrectionFactory extends Factory
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
            'subscription_id' => Subscription::factory(),
            'register_shift_id' => null,
            'payment_method' => 'cash',
            'old_amount_paid' => 40,
            'new_amount_paid' => 15,
            'subscription_amount' => 60,
            'net_amount' => 35,
            'reason' => fake()->sentence(),
            'corrected_by' => User::factory(),
        ];
    }
}
