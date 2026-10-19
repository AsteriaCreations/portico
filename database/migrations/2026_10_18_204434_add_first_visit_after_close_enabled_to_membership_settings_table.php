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
        // Whether the desk still checks in a member's first visit after an
        // event's check-in has closed (Event::isCheckInClosedFor()). Default
        // true keeps what upgrading installs already do.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('first_visit_after_close_enabled')->default(true)->after('checkin_close_minutes_same_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('first_visit_after_close_enabled');
        });
    }
};
