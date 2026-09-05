<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberPaperwork;
use App\Models\PaperworkType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberPaperwork>
 */
class MemberPaperworkFactory extends Factory
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
            'paperwork_type_id' => PaperworkType::factory(),
            'signed_on' => now()->toDateString(),
            'recorded_by' => null,
        ];
    }
}
