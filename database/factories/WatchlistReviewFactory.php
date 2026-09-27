<?php

namespace Database\Factories;

use App\Enums\WatchlistReviewDecision;
use App\Models\Member;
use App\Models\User;
use App\Models\WatchlistReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchlistReview>
 */
class WatchlistReviewFactory extends Factory
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
            'decision' => WatchlistReviewDecision::KeptIndefinitely,
            'previous_review_on' => today()->toDateString(),
            'new_review_on' => null,
            'probation_started' => false,
            'notes' => null,
            'decided_by' => User::factory(),
        ];
    }
}
