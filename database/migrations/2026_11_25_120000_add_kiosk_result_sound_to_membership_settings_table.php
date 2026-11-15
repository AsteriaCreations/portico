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
        // Whether the kiosk plays a short tone with each scan's result. Off
        // by default: a tablet shouldn't start making noise after an update.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('kiosk_result_sound')->default(false)->after('kiosk_idle_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_result_sound');
        });
    }
};
