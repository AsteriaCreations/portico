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
        // Whether staff may send a member's kiosk QR code to the kiosk
        // tablet for the member to photograph. Off by default: a new
        // capability, so upgrading changes nothing until a club opts in.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('kiosk_show_code_enabled')->default(false)->after('kiosk_checkin_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_show_code_enabled');
        });
    }
};
