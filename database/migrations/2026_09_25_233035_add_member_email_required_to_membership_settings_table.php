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
        // Whether the Check-In Desk requires an email when a Prospective
        // finishes sign-up or a guest is registered, and whether a
        // Prospective without one still counts as needing sign-up. Defaults
        // true, which is what the desk always did, so upgrading changes nothing.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('member_email_required')->default(true)->after('checkin_display_name_field');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('member_email_required');
        });
    }
};
