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
        // Whether picking a different member on the Check-In Desk puts the
        // Event back to the page's default (or the one event that member is
        // already on tonight), so a pick made for one person can't carry
        // over to the next. On by default: it fixes check-ins landing on the
        // wrong one of two overlapping events.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('reset_desk_event_on_member_change')->default(true)->after('door_preferred_name_change_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('reset_desk_event_on_member_change');
        });
    }
};
