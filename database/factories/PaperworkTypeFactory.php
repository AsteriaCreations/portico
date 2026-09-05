<?php

namespace Database\Factories;

use App\Models\PaperworkType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaperworkType>
 */
class PaperworkTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'required' => true,
            'renewal_months' => null,
            'gates_add_on_id' => null,
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function annual(): static
    {
        return $this->state(fn () => ['renewal_months' => 12]);
    }
}
