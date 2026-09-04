<?php

namespace Database\Seeders;

use App\Enums\AddOnKind;
use App\Models\AddOn;
use Illuminate\Database\Seeder;

class AddOnSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // The one protected row every Regular subscription targets -- see
        // the add_ons migration's own comment for why this exists.
        AddOn::create([
            'name' => AddOn::ENTRY_NAME,
            'kind' => AddOnKind::Entry,
            'subscribable' => true,
            'sort_order' => -1,
        ]);

        // Priced per event (events.pool_fee), not a flat catalog price --
        // some events have no pool at all. Subscribable: a Pool subscription
        // or day pass covers it, same as before this was a real add_ons row.
        AddOn::create([
            'name' => AddOn::POOL_NAME,
            'priced_per_event' => true,
            'subscribable' => true,
            'sort_order' => 0,
        ]);

        AddOn::create([
            'name' => 'Private room rental',
            'price' => 50,
            'max_per_night' => 1, // Only one rentable room on site.
            'is_overnight' => true,
            'sort_order' => 1,
        ]);

        AddOn::create([
            'name' => 'Sleepover',
            'price' => 25,
            'is_overnight' => true,
            'sort_order' => 2,
        ]);
    }
}
