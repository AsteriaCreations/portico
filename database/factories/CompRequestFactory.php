<?php

namespace Database\Factories;

use App\Enums\CompRequestStatus;
use App\Models\CompReason;
use App\Models\CompRequest;
use App\Models\Event;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompRequest>
 */
class CompRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'member_id' => Member::factory(),
            'comp_reason_id' => CompReason::factory(),
            'requested_by' => User::factory(),
            'notes' => null,
            'status' => CompRequestStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'attendance_id' => null,
        ];
    }
}
