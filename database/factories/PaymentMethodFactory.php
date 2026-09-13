<?php

namespace Database\Factories;

use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentMethod>
 */
class PaymentMethodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->unique()->word(),
            'code' => fake()->unique()->slug(2),
            'requires_register_shift' => false,
            'one_time_only' => false,
            'transaction_fee' => 0,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
