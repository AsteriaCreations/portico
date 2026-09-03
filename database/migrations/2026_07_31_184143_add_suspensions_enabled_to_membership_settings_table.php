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
        // Defaulted true -- see the commit history for why every new
        // flag on this table defaults to whatever preserves existing
        // behavior. Gates only the ability to SET banned_until (declutter
        // for a club that never uses suspensions) -- never affects reading
        // an already-set date, same as every other feature flag here.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('suspensions_enabled')->default(true)->after('manager_perk_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('suspensions_enabled');
        });
    }
};
