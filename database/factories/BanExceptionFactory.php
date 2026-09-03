<?php

namespace Database\Factories;

use App\Models\BanException;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BanException>
 */
class BanExceptionFactory extends Factory
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
            'granted_by' => User::factory(),
            'reason' => fake()->sentence(),
        ];
    }
}
