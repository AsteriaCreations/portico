<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Member;
use App\Models\PoolDayPass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PoolDayPass>
 */
class PoolDayPassFactory extends Factory
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
            'event_id' => Event::factory(),
            'amount_paid' => 15,
            'payment_method' => 'cash',
            'register_shift_id' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
