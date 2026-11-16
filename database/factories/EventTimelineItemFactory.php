<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventTimelineCategory;
use App\Models\EventTimelineItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventTimelineItem>
 */
class EventTimelineItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'event_timeline_category_id' => EventTimelineCategory::factory(),
            'title' => fake()->words(3, true),
            'description' => null,
            'starts_at' => now(),
            'ends_at' => now()->addMinutes(30),
            'created_by' => User::factory(),
            'updated_by' => fn (array $attributes) => $attributes['created_by'],
        ];
    }
}
