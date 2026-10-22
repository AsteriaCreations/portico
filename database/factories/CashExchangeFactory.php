<?php

namespace Database\Factories;

use App\Models\CashExchange;
use App\Models\RegisterShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashExchange>
 */
class CashExchangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'register_shift_id' => RegisterShift::factory(),
            'payment_method' => 'venmo',
            'amount' => 20,
            'fee' => 0,
            'notes' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
