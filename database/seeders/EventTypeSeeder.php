<?php

namespace Database\Seeders;

use App\Models\EventType;
use Illuminate\Database\Seeder;

class EventTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $eventTypes = [
            'Social',
            'Pool Social',
            'Class',
            'Munch',
            'Private Rental',
            'Meeting',
            'Special',
            'Yoga',
        ];

        foreach ($eventTypes as $sortOrder => $name) {
            EventType::create([
                'name' => $name,
                'sort_order' => $sortOrder,
            ]);
        }
    }
}
