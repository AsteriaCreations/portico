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
        // How long the kiosk waits with no code in view before it turns its
        // camera off to save the tablet's battery; a tap turns it back on.
        // 0 keeps the camera on all night, which is what the kiosk always did.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('kiosk_idle_seconds')->default(0)->after('kiosk_same_code_cooldown_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_idle_seconds');
        });
    }
};
