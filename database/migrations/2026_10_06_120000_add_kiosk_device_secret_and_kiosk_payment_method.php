<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A SHA-256 of the shared secret the kiosk tablet sends with every
        // scan. Only the hash is kept: the secret is shown once when an
        // Admin sets up a device, and a lost one is simply replaced. See
        // MembershipSetting::regenerateKioskDeviceSecret().
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('kiosk_device_secret_hash', 64)->nullable()->after('kiosk_checkin_enabled');
        });

        // What a kiosk check-in records as its payment method, so those
        // visits stay visible as their own line. Inactive and off the desk:
        // nobody ever picks it, only the kiosk writes it. Skipped if a club
        // already has a method coded or labelled this way.
        $taken = DB::table('payment_methods')->where('code', 'kiosk')->orWhere('label', 'Kiosk')->exists();
        if (! $taken) {
            DB::table('payment_methods')->insert([
                'label' => 'Kiosk',
                'code' => 'kiosk',
                'requires_register_shift' => false,
                'one_time_only' => false,
                'available_at_desk' => false,
                'sort_order' => (int) DB::table('payment_methods')->max('sort_order') + 1,
                'active' => false,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('kiosk_device_secret_hash');
        });

        // Left in place if any visit already recorded it.
        if (! DB::table('attendance')->where('payment_method', 'kiosk')->exists()) {
            DB::table('payment_methods')->where('code', 'kiosk')->delete();
        }
    }
};
