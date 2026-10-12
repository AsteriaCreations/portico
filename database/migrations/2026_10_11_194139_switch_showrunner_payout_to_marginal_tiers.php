<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The showrunner payout moved from "one tier's rate on the whole door" to
 * brackets: each percentage tier pays its rate on the listed entry fee for the
 * attendees numbered inside it (ShowrunnerPayoutService). That base is the
 * entry fee alone, so the two "include pool / add-on revenue in the door"
 * settings no longer mean anything and are dropped.
 *
 * Tier ranges are now read as inclusive attendee numbers. The seeded
 * defaults (0-34 voucher, 35-100 at 10%, 101+ at 15%) are moved to
 * 0-35 / 36-100 / 101+ so the 10% band covers attendees 36 to 100 (65
 * people). Only an install still on exactly those defaults is touched; a
 * club that set its own tiers keeps them and should re-check them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['showrunner_door_includes_pool', 'showrunner_door_includes_addons']);
        });

        $this->moveDefaultTiers(from: [34, 35], to: [35, 36]);
    }

    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('showrunner_door_includes_pool')->default(false);
            $table->boolean('showrunner_door_includes_addons')->default(false);
        });

        $this->moveDefaultTiers(from: [35, 36], to: [34, 35]);
    }

    /**
     * @param  array{int, int}  $from  [voucher max, 10% tier min] currently expected
     * @param  array{int, int}  $to  [voucher max, 10% tier min] to set
     */
    private function moveDefaultTiers(array $from, array $to): void
    {
        $tiers = DB::table('showrunner_payout_tiers')->orderBy('min_headcount')->get();

        $matchesDefaults = $tiers->count() === 3
            && $tiers[0]->min_headcount == 0 && $tiers[0]->max_headcount == $from[0] && $tiers[0]->payout_type === 'voucher' && (float) $tiers[0]->payout_value === 25.0
            && $tiers[1]->min_headcount == $from[1] && $tiers[1]->max_headcount == 100 && $tiers[1]->payout_type === 'percentage' && (float) $tiers[1]->payout_value === 10.0
            && $tiers[2]->min_headcount == 101 && $tiers[2]->max_headcount === null && $tiers[2]->payout_type === 'percentage' && (float) $tiers[2]->payout_value === 15.0;

        if (! $matchesDefaults) {
            return;
        }

        DB::table('showrunner_payout_tiers')->where('id', $tiers[0]->id)->update(['max_headcount' => $to[0]]);
        DB::table('showrunner_payout_tiers')->where('id', $tiers[1]->id)->update(['min_headcount' => $to[1]]);
    }
};
