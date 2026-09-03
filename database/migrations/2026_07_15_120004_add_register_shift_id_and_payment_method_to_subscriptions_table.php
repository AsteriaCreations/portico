<?php

use App\Models\RegisterShift;
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
        // A subscription collected during check-in shares the same
        // transaction (and payment method) as the entry fee, but previously
        // recorded neither the register shift it belonged to nor how it was
        // paid. Mirrors attendance.payment_method / attendance.register_shift_id.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('payment_method', 30)->nullable()->after('notes');
            $table->foreignIdFor(RegisterShift::class)->nullable()->after('notes')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(RegisterShift::class);
            $table->dropColumn('payment_method');
        });
    }
};
