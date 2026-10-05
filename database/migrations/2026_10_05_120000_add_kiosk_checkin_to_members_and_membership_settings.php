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
        // The code a member's kiosk QR carries. It only identifies the
        // member: every kiosk scan re-checks admission, subscription and
        // capacity live, so a lapsed member's printed code simply stops
        // admitting them. Set only by Member::ensureKioskToken() /
        // regenerateKioskToken(), never by a form.
        Schema::table('members', function (Blueprint $table) {
            $table->string('kiosk_token', 64)->nullable()->unique()->after('guest_followup_sent_by');
            $table->timestamp('kiosk_token_generated_at')->nullable()->after('kiosk_token');
        });

        // A new capability, so off until a club opts in on Feature Flags.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('kiosk_checkin_enabled')->default(false)->after('door_username_rename_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['kiosk_token']);
            $table->dropColumn(['kiosk_token', 'kiosk_token_generated_at']);
        });

        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_checkin_enabled');
        });
    }
};
