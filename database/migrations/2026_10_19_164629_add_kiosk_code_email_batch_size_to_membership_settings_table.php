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
        // How many kiosk codes one "Email kiosk codes" click sends. It was
        // fixed at 50 (KioskCodeMailer::BATCH_SIZE).
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('kiosk_code_email_batch_size')->default(50)->after('kiosk_requests_per_minute');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_code_email_batch_size');
        });
    }
};
