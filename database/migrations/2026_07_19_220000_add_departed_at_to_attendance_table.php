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
        // Set when a specific, known patron is departed from the Active
        // Patrons screen. Distinct from occupancy_adjustments, which stays
        // an anonymous "N people left" ledger for when staff don't know
        // exactly who. CapacityService::occupancy() excludes departed rows.
        Schema::table('attendance', function (Blueprint $table) {
            $table->timestamp('departed_at')->nullable()->after('checked_in_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('departed_at');
        });
    }
};
