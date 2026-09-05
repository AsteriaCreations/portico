<?php

namespace Database\Seeders;

use App\Models\AddOn;
use App\Models\PaperworkType;
use Illuminate\Database\Seeder;

class PaperworkTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * updateOrCreate, not create: the seed_paperwork_types_and_backfill
     * migration also writes these two rows (so a plain `migrate` on an
     * existing install picks the feature up), but it can't always resolve
     * AddOn::pool()'s id. Running after AddOnSeeder, this seeder can -- so
     * on a fresh install it confirms/corrects the Pool Waiver's gate.
     */
    public function run(): void
    {
        PaperworkType::updateOrCreate(
            ['name' => 'Standard Paperwork'],
            [
                'description' => 'The membership paperwork / NDA every member signs once.',
                'required' => true,
                'renewal_months' => null,
                'gates_add_on_id' => null,
                'sort_order' => 0,
                'active' => true,
            ],
        );

        PaperworkType::updateOrCreate(
            ['name' => 'Pool Waiver'],
            [
                'description' => 'Liability waiver required before a member may use the pool. Renews yearly.',
                'required' => true,
                'renewal_months' => 12,
                'gates_add_on_id' => AddOn::pool()?->id,
                'sort_order' => 1,
                'active' => true,
            ],
        );
    }
}
