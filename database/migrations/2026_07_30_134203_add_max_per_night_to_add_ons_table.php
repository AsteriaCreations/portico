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
        // Null = unlimited, same convention as membership_settings.venue_capacity
        // -- caps how many units of this add-on (e.g. "Private room rental")
        // can be sold across all of a night's concurrent events, mirroring
        // CapacityService's own building-wide, date-scoped counting.
        Schema::table('add_ons', function (Blueprint $table) {
            $table->unsignedInteger('max_per_night')->nullable()->after('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('add_ons', function (Blueprint $table) {
            $table->dropColumn('max_per_night');
        });
    }
};
