<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\EventTimelineCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventTimelineCategory>
 */
class EventTimelineCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'color' => '#4b5563',
            'is_public' => false,
            'min_role' => Role::Volunteer,
            'sort_order' => 0,
        ];
    }

    public function public(): static
    {
        return $this->state(['is_public' => true, 'min_role' => null]);
    }

    public function forRole(?Role $role): static
    {
        return $this->state(['is_public' => false, 'min_role' => $role]);
    }
}
