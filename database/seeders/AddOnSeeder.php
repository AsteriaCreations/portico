<?php

namespace Database\Seeders;

use App\Models\AddOn;
use Illuminate\Database\Seeder;

class AddOnSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AddOn::create([
            'name' => 'Private room rental',
            'price' => 50,
            'max_per_night' => 1, // Only one rentable room on site.
            'is_overnight' => true,
            'sort_order' => 0,
        ]);

        AddOn::create([
            'name' => 'Sleepover',
            'price' => 25,
            'is_overnight' => true,
            'sort_order' => 1,
        ]);
    }
}
