<?php

namespace Database\Seeders;

use App\Models\CompReason;
use Illuminate\Database\Seeder;

class CompReasonSeeder extends Seeder
{
    /**
     * A small starter set, editable at runtime like event_types and plans.
     * A club adds, renames, or deactivates these to match how it comps.
     */
    public function run(): void
    {
        $reasons = [
            [
                'name' => 'Presenter',
                'description' => 'Ran a class or demo at this event.',
                'grants_voucher_amount' => 25,
            ],
            [
                'name' => 'Volunteer',
                'description' => 'Worked the event (door, setup, cleanup).',
            ],
            [
                'name' => 'Guest of a staff member',
                'description' => 'Attending as a comped guest of a Manager or Owner.',
            ],
        ];

        foreach ($reasons as $sortOrder => $reason) {
            CompReason::create($reason + ['sort_order' => $sortOrder]);
        }
    }
}
