<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use App\Models\VisitRemoval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitRemoval>
 */
class VisitRemovalFactory extends Factory
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
            'register_shift_id' => null,
            'payment_method' => 'cash',
            'amount_paid' => 40,
            'checked_in_at' => now(),
            'after_shift_closed' => false,
            'reason' => fake()->sentence(),
            'removed_by' => User::factory(),
        ];
    }
}
