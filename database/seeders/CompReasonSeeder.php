<?php

namespace Database\Seeders;

use App\Models\CompReason;
use Illuminate\Database\Seeder;

class CompReasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        CompReason::create([
            'name' => 'House Sub',
            'sort_order' => 0,
        ]);
    }
}
