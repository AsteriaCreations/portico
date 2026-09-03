<?php

namespace Database\Factories;

use App\Enums\EntryCoverageSource;
use App\Models\EventType;
use App\Models\InstructorPayRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstructorPayRate>
 */
class InstructorPayRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_type_id' => EventType::factory(),
            'entry_covered_by' => EntryCoverageSource::None,
            'rate' => fake()->randomFloat(2, 5, 25),
        ];
    }
}
