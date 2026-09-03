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
        // Editable settings data, not a hardcoded enum, same pattern as
        // event_types/comp_reasons/registers. `code` is the literal value
        // already stored in attendance.payment_method/subscriptions.payment_method
        // (plain strings, never an FK — those columns are payment snapshots
        // and must never be rewritten if a method is later renamed), so code
        // is treated as immutable once created (see PaymentMethodForm).
        // requires_register_shift replaces the old hardcoded "cash requires
        // an open shift" check — a method flagged true (a) can only be
        // selected while a register shift is open and (b) counts toward
        // RegisterShiftService::cashReceived()'s box reconciliation, since it
        // physically deposits money in the drawer the same way cash does.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60)->unique();
            $table->string('code', 30)->unique();
            $table->boolean('requires_register_shift')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
        });

        // Seeded with the 4 values that were previously hardcoded in
        // CheckIn.php/ListSubscriptions.php, so behavior is unchanged on
        // both a fresh install and an existing one.
        DB::table('payment_methods')->insert([
            ['label' => 'Cash', 'code' => 'cash', 'requires_register_shift' => true, 'sort_order' => 1, 'active' => true],
            ['label' => 'Venmo', 'code' => 'venmo', 'requires_register_shift' => false, 'sort_order' => 2, 'active' => true],
            ['label' => 'Comp', 'code' => 'comp', 'requires_register_shift' => false, 'sort_order' => 3, 'active' => true],
            ['label' => 'Other', 'code' => 'other', 'requires_register_shift' => false, 'sort_order' => 4, 'active' => true],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
