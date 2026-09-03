<?php

namespace Database\Seeders;

use App\Enums\EntryCoverageSource;
use App\Models\EventType;
use App\Models\InstructorPayRate;
use Illuminate\Database\Seeder;

class InstructorPayRateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $yoga = EventType::where('name', 'Yoga')->first();

        if (! $yoga) {
            return;
        }

        InstructorPayRate::create([
            'event_type_id' => $yoga->id,
            'entry_covered_by' => EntryCoverageSource::None,
            'rate' => 10.00,
        ]);

        InstructorPayRate::create([
            'event_type_id' => $yoga->id,
            'entry_covered_by' => EntryCoverageSource::RegularSubscription,
            'rate' => 5.00,
        ]);
    }
}
