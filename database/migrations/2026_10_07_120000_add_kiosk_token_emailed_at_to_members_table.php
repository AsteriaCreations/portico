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
        // When the member was last emailed their kiosk code, so "Email kiosk
        // codes to subscribers" can run in batches and never sends twice.
        // Cleared when the code is replaced. See App\Services\KioskCodeMailer.
        Schema::table('members', function (Blueprint $table) {
            $table->timestamp('kiosk_token_emailed_at')->nullable()->after('kiosk_token_generated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('kiosk_token_emailed_at');
        });
    }
};
