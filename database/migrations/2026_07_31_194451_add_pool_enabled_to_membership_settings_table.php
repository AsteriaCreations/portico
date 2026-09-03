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
        // disables pool coverage already in use; a fresh install without a
        // pool can turn it off on the Feature Flags page.
        // Unlike the previous flag rounds, pool coverage math lives inside
        // PricingService::build() itself (gated by pool_fee > 0, not a
        // separate applyX() layer) -- this flag only stops NEW pool
        // commitments (events with a nonzero pool_fee, Pool subscriptions,
        // pool day passes) from being created going forward. It never
        // reinterprets existing data. See the commit history.
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->boolean('pool_enabled')->default(true)->after('suspensions_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_settings', function (Blueprint $table) {
            $table->dropColumn('pool_enabled');
        });
    }
};
