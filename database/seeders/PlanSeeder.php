<?php

namespace Database\Seeders;

use App\Enums\PlanType;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Plan::create([
            'code' => PlanType::Regular,
            'price' => 60.00,
            'credit' => 25.00,
            'effective_from' => '2026-01-01',
        ]);

        Plan::create([
            'code' => PlanType::Pool,
            'price' => 15.00,
            'credit' => null, // full coverage of event.pool_fee, not a fixed credit
            'effective_from' => '2026-01-01',
        ]);
    }
}
