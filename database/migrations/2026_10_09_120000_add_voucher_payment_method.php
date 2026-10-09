<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // What a check-in records as its payment method when voucher credit
        // (the member's own or someone else's) covers everything, so a visit
        // where no money changed hands never reads as Cash or whatever the
        // desk happened to pick. Inactive and off the desk, like Kiosk:
        // nobody picks it, only CheckInService writes it. Skipped if a club
        // already has a method coded or labelled this way.
        $taken = DB::table('payment_methods')->where('code', 'voucher')->orWhere('label', 'Voucher')->exists();
        if (! $taken) {
            DB::table('payment_methods')->insert([
                'label' => 'Voucher',
                'code' => 'voucher',
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
        // Left in place if any visit already recorded it.
        if (! DB::table('attendance')->where('payment_method', 'voucher')->exists()) {
            DB::table('payment_methods')->where('code', 'voucher')->delete();
        }
    }
};
