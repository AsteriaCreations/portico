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
        // A method flagged one_time_only may be selected once per member,
        // ever — doing so appends a dated marker to the member's
        // hospitality_note and disables every one_time_only method for them
        // thereafter (Member::hasUsedOneTimeMethod()). Generalises the old
        // hardcoded "Venmo is one use per member" house rule so PayPal and
        // any other electronic method get the same treatment.
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('one_time_only')->default(false)->after('requires_register_shift');
        });

        // Venmo keeps its existing one-time behaviour, now flag-driven.
        DB::table('payment_methods')->where('code', 'venmo')->update(['one_time_only' => true]);

        // PayPal is a door option; 'electronic' is a neutral code the
        // historical roster import writes for the legacy sheet's "EP"
        // (Electronic Payment) cells and a usable option for a club that
        // takes generic card/electronic payment.
        DB::table('payment_methods')->insert([
            ['label' => 'PayPal', 'code' => 'paypal', 'requires_register_shift' => false, 'one_time_only' => true, 'sort_order' => 5, 'active' => true],
            ['label' => 'Electronic', 'code' => 'electronic', 'requires_register_shift' => false, 'one_time_only' => true, 'sort_order' => 6, 'active' => true],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('payment_methods')->whereIn('code', ['paypal', 'electronic'])->delete();

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('one_time_only');
        });
    }
};
