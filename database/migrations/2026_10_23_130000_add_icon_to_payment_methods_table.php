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
        // The icon the Check-In Desk's "Checked in tonight" list shows beside
        // what someone paid: one of PaymentMethod::ICONS, picked per method
        // on Payment Methods. Null shows none.
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('icon', 40)->nullable()->after('label');
        });

        // A starting icon for the methods Portico ships or clubs commonly
        // add, matched by code; any other method starts with none.
        foreach ([
            'cash' => 'banknotes',
            'venmo' => 'device-phone-mobile',
            'paypal' => 'globe-alt',
            'electronic' => 'credit-card',
            'card' => 'credit-card',
            'comp' => 'gift',
            'voucher' => 'wallet',
            'kiosk' => 'qr-code',
            'other' => 'ellipsis-horizontal-circle',
        ] as $code => $icon) {
            DB::table('payment_methods')->where('code', $code)->update(['icon' => $icon]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
