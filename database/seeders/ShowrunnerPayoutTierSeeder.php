<?php

namespace Database\Seeders;

use App\Enums\PayoutType;
use App\Models\ShowrunnerPayoutTier;
use Illuminate\Database\Seeder;

class ShowrunnerPayoutTierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ShowrunnerPayoutTier::create([
            'min_headcount' => 0,
            'max_headcount' => 34,
            'payout_type' => PayoutType::Voucher,
            'payout_value' => 25.00,
        ]);

        ShowrunnerPayoutTier::create([
            'min_headcount' => 35,
            'max_headcount' => 100,
            'payout_type' => PayoutType::Percentage,
            'payout_value' => 10.00,
        ]);

        ShowrunnerPayoutTier::create([
            'min_headcount' => 101,
            'max_headcount' => null,
            'payout_type' => PayoutType::Percentage,
            'payout_value' => 15.00,
        ]);
    }
}
