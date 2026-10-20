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
        // How long the kiosk shows a scan's result, and how long it ignores
        // the same code held up again. They were fixed in kiosk.js at 4 s
        // (checked in / already checked in), 7 s (anything else) and 8 s.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('kiosk_result_seconds')->default(4)->after('kiosk_subscribers_only');
            $table->unsignedSmallInteger('kiosk_problem_result_seconds')->default(7)->after('kiosk_result_seconds');
            $table->unsignedSmallInteger('kiosk_same_code_cooldown_seconds')->default(8)->after('kiosk_problem_result_seconds');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn(['kiosk_result_seconds', 'kiosk_problem_result_seconds', 'kiosk_same_code_cooldown_seconds']);
        });
    }
};
