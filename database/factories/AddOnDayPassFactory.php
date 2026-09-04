<?php

namespace Database\Factories;

use App\Models\AddOn;
use App\Models\AddOnDayPass;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddOnDayPass>
 */
class AddOnDayPassFactory extends Factory
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
            'add_on_id' => AddOn::factory(),
            'amount_paid' => 15,
            'payment_method' => 'cash',
            'register_shift_id' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
