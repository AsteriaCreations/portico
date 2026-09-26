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
        // How long a member stays on probation after an Owner takes them off
        // the watchlist (null = no probation), and whether that probation
        // also stops them sponsoring guests. Both off by default, so
        // upgrading changes nothing until a club sets them.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('watchlist_probation_days')->nullable()->after('watchlist_notify_label');
            $table->boolean('watchlist_probation_blocks_guests')->default(false)->after('watchlist_probation_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['watchlist_probation_days', 'watchlist_probation_blocks_guests']);
        });
    }
};
