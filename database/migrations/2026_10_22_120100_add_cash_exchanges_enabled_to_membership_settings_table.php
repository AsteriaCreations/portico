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
        // Whether the desk's cash box offers "Cash for an electronic
        // payment". Off by default: a new capability, so upgrading changes
        // nothing until a club opts in.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('cash_exchanges_enabled')->default(false)->after('cash_envelope_reminder_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('cash_exchanges_enabled');
        });
    }
};
