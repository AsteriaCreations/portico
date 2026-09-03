<?php

namespace Database\Factories;

use App\Enums\Capability;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserCapability>
 */
class UserCapabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'capability' => Capability::CleaningCrew,
            'granted_by' => User::factory(),
        ];
    }
}
