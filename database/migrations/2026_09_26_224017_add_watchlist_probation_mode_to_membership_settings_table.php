<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // How watchlist probation is sized: 'same' follows new-member
        // probation (its length and its guest rule), 'custom' uses the
        // club's own watchlist_probation_days / watchlist_probation_blocks_guests,
        // 'off' turns it off. See MembershipSetting::watchlistProbationDays().
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('watchlist_probation_mode', 10)->default('same')->after('watchlist_notify_label');
        });

        // A club that already set its own length keeps it.
        DB::table('membership_settings')->whereNotNull('watchlist_probation_days')->update(['watchlist_probation_mode' => 'custom']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('watchlist_probation_mode');
        });
    }
};
