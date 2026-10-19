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
        // Whether cash the desk takes for a later event is held for that
        // event, outside the register box (Attendance::prepaid_ahead). Off
        // leaves it in tonight's box. Default true keeps what upgrading
        // installs already do.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('hold_desk_prepay_cash')->default(true)->after('cash_envelope_reminder_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('hold_desk_prepay_cash');
        });
    }
};
