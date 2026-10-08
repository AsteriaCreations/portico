<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Whether the Check-In Desk may sell a day pass (Pool) for an event
        // that isn't running tonight. Off: a day pass sold for a later event
        // lands in tonight's box with nothing tying it to that event (unlike
        // a prepaid entry, see attendance.prepaid_ahead), so the desk only
        // offers tonight's events unless a club opts back in.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('day_pass_future_events_enabled')->default(false)->after('pool_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('day_pass_future_events_enabled');
        });
    }
};
