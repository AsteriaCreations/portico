<?php

namespace Database\Seeders;

use App\Models\AddOn;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds. Depends on AddOnSeeder having already run.
     */
    public function run(): void
    {
        Plan::create([
            'add_on_id' => AddOn::entry()->id,
            'price' => 60.00,
            'credit' => 25.00,
            'effective_from' => '2026-01-01',
        ]);

        Plan::create([
            'add_on_id' => AddOn::pool()->id,
            'price' => 15.00,
            'credit' => null, // full coverage of the event's pool price, not a fixed credit
            'effective_from' => '2026-01-01',
        ]);
    }
}
