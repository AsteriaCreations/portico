<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_number' => fake()->unique()->numberBetween(1, 9999),
            'username' => fake()->unique()->userName(),
            'preferred_name' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->safeEmail(),
            'email_opt_in' => false,
            'category_id' => Category::factory(),
            // Bounded well past any realistic probation_period_days (default
            // 90) so a factory-created member is never randomly on probation
            // by default — fake()->date() alone picks anywhere up to today,
            // which intermittently landed inside the probation window and
            // flakily hid sponsor-gated actions (e.g. registerGuestAction)
            // in tests that don't override date_vetted explicitly.
            'date_vetted' => fake()->dateTimeBetween('-5 years', '-1 year')->format('Y-m-d'),
            'dob' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'is_active' => true,
            'subscription_eligible' => false,
            'on_watchlist' => false,
            'watchlist_reason' => null,
            'is_banned' => false,
            'ban_reason' => null,
            'probation_override_start' => null,
            'missing_paperwork' => false,
            'is_deceased' => false,
            'hospitality_note' => null,
            'notes' => null,
        ];
    }
}
