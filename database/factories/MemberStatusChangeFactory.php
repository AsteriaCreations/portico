<?php

namespace Database\Factories;

use App\Enums\MemberStatusField;
use App\Models\Member;
use App\Models\MemberStatusChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberStatusChange>
 */
class MemberStatusChangeFactory extends Factory
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
            'status' => MemberStatusField::Banned,
            'value' => true,
            'reason' => fake()->sentence(),
            'changed_by' => User::factory(),
        ];
    }
}
