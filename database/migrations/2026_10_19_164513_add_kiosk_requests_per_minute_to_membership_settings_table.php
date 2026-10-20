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
        // The kiosk rate limit (per tablet IP, per endpoint). It was fixed
        // at 30 a minute in AppServiceProvider.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('kiosk_requests_per_minute')->default(30)->after('kiosk_same_code_cooldown_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_requests_per_minute');
        });
    }
};
