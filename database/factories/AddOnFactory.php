<?php

namespace Database\Factories;

use App\Enums\AddOnKind;
use App\Models\AddOn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddOn>
 */
class AddOnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'kind' => AddOnKind::Addon,
            'priced_per_event' => false,
            'price' => fake()->randomFloat(2, 5, 100),
            'subscribable' => false,
            'max_per_night' => null,
            'is_overnight' => false,
            'description' => fake()->sentence(),
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function subscribable(): static
    {
        return $this->state(fn (array $attributes) => ['subscribable' => true]);
    }

    /**
     * Mirrors the seeded 'Pool' row: subscribable, priced per event
     * (events.pool_fee), no flat catalog price.
     */
    public function pool(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => AddOn::POOL_NAME,
            'subscribable' => true,
            'priced_per_event' => true,
            'price' => null,
        ]);
    }
}
