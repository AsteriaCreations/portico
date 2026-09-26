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
        // guests_enabled: whether the Check-In Desk offers "Register a guest"
        // at all. guests_allowed_during_probation: whether a member still on
        // probation may sponsor one. Both default to what the desk always did
        // (guests on, blocked during probation), so upgrading changes nothing.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('guests_enabled')->default(true)->after('behavior_notes_enabled');
            $table->boolean('guests_allowed_during_probation')->default(false)->after('probation_period_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['guests_enabled', 'guests_allowed_during_probation']);
        });
    }
};
