<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberUsernameChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberUsernameChange>
 */
class MemberUsernameChangeFactory extends Factory
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
            'old_username' => fake()->unique()->userName(),
            'new_username' => fake()->unique()->userName(),
            'changed_by' => User::factory(),
        ];
    }
}
