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
        // A flat per-transaction surcharge that models the real cost of
        // accepting a given method (Venmo/PayPal/electronic processors all
        // take a cut) — folded straight into amount_paid at the point of
        // sale rather than tracked as its own ledger, so RegisterShiftService's
        // reconciliation needs zero changes (same "fold into the existing
        // column" choice already made for Event Add-Ons).
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->decimal('transaction_fee', 8, 2)->default(0)->after('one_time_only');
        });

        DB::table('payment_methods')->whereIn('code', ['venmo', 'paypal', 'electronic'])->update(['transaction_fee' => 2.00]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('transaction_fee');
        });
    }
};
