<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Voucher>
 */
class VoucherFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'amount' => 25,
            'reason' => fake()->sentence(),
            'attendance_id' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
