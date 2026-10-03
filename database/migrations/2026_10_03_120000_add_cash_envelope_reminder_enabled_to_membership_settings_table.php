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
        // Whether closing the register box shows a reminder to envelope the
        // cash collected, with its Entry/Subscription/Other breakdown. Off
        // by default: a new capability, so upgrading changes nothing until
        // a club opts in.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('cash_envelope_reminder_enabled')->default(false)->after('register_shifts_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('cash_envelope_reminder_enabled');
        });
    }
};
