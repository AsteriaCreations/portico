<?php

namespace Database\Factories;

use App\Enums\EntryCoverageSource;
use App\Models\Attendance;
use App\Models\Event;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
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
            'checked_in_by' => null,
            'checked_in_at' => now(),
            'entry_fee' => 0,
            'entry_coverage' => 0,
            'entry_covered_by' => EntryCoverageSource::None,
            'voucher_coverage' => 0,
            'amount_paid' => 0,
            'payment_method' => null,
            'on_behalf_note' => null,
            'notes' => null,
        ];
    }
}
