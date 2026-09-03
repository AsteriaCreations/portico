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
        // Defaulted true -- register-shift tracking is already live for this
        // club, so upgrading an existing install must never silently disable
        // it. RegisterShiftService itself needs no gating: it's already
        // fully driven by PaymentMethod.requires_register_shift, so a club
        // with none flagged already gets an inert reconciliation path. The
        // real gap this flag closes is check-in.blade.php's Register section
        // rendering unconditionally even with zero Register rows configured.
        // See the commit history.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('register_shifts_enabled')->default(true)->after('prepay_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('register_shifts_enabled');
        });
    }
};
