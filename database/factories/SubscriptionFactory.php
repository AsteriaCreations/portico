<?php

namespace Database\Factories;

use App\Enums\PlanType;
use App\Models\Member;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
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
            'plan_type' => fake()->randomElement(PlanType::cases()),
            'covered_month' => now()->startOfMonth()->toDateString(),
            'amount_paid' => fake()->randomFloat(2, 15, 60),
            'paid_on' => fake()->date(),
            'recorded_by' => null,
        ];
    }
}
