<?php

namespace Database\Factories;

use App\Models\IssueReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IssueReport>
 */
class IssueReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page' => 'check-in',
            'note' => fake()->sentence(),
            'snapshot' => ['page' => 'check-in'],
            'reported_by' => User::factory(),
        ];
    }
}
