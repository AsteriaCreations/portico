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
        // Defaulted true so upgrading an existing install never silently
        // disables prepay already in use; a fresh install that never takes
        // payment before event day can turn it off on the Feature Flags page.
        // Capacity enforcement (the other half of "Prepay events / building
        // capacity") deliberately gets no flag of its own -- CapacityService
        // ::hasRoom() already returns true unconditionally when
        // venue_capacity is null, a fully-working zero-code opt-out every
        // consumer already respects. See the commit history.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('prepay_enabled')->default(true)->after('pool_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('prepay_enabled');
        });
    }
};
