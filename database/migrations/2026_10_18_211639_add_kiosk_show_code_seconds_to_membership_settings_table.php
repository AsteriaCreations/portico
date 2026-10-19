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
        // How long "Show on kiosk" keeps a member's code up on the tablet
        // (and waits for the tablet to collect it). It was fixed at 60.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('kiosk_show_code_seconds')->default(60)->after('kiosk_show_code_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_show_code_seconds');
        });
    }
};
