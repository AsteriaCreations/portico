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
        // What a comped category (Owner/Manager/Emeritus/Staff) pays for
        // entry at this event instead of getting in free. Null keeps the
        // usual full comp; it's capped at entry_fee when priced. Add-ons
        // stay comped either way — see PricingService::build().
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('comped_entry_fee', 8, 2)->nullable()->after('entry_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('comped_entry_fee');
        });
    }
};
