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
            // lexify() keeps it a fixed 15 characters: slug(2) can return more words
            // than asked and overflow the 30-character column on MySQL/MariaDB.
            'code' => fake()->unique()->lexify('method-????????'),
            'requires_register_shift' => false,
            'one_time_only' => false,
            'transaction_fee' => 0,
            'sort_order' => 0,
            'active' => true,
        ];
    }
}
