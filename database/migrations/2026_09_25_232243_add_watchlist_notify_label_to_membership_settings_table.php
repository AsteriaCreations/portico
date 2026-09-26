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
        // Where staff post a heads-up about a watchlisted member ("Notify
        // <label>"). Null falls back to config('membership.watchlist_notify_label')
        // (WATCHLIST_NOTIFY_LABEL in .env), so upgrading changes nothing until
        // someone sets it on Membership Settings.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('watchlist_notify_label', 60)->nullable()->after('alcohol_flag_age');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('watchlist_notify_label');
        });
    }
};
