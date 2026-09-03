<?php

namespace Database\Factories;

use App\Enums\PayoutType;
use App\Models\ShowrunnerPayoutTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShowrunnerPayoutTier>
 */
class ShowrunnerPayoutTierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'min_headcount' => 0,
            'max_headcount' => null,
            'payout_type' => PayoutType::Percentage,
            'payout_value' => 10,
        ];
    }
}
