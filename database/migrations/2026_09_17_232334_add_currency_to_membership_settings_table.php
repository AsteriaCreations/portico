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
        // Every dollar figure in the app -- Analytics widgets, the check-in
        // desk's live totals/notifications, and every Filament ->money()
        // table column -- assumed USD with no override, hardcoded either as
        // a literal '$' or via ->money() falling back to Filament's own
        // 'usd' default. Defaulted to 'USD' so upgrading changes nothing.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('alcohol_flag_age');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
